<?php

namespace App\Services\Payment;

use GuzzleHttp\Client;
use App\Traits\ApiResponseHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Exception\GuzzleException;
use App\Exceptions\PaymentGatewayException;

class PaystackService
{
    use ApiResponseHandler;

    private $client;
    private $secretKey;
    private $baseUrl;

    public function __construct()
    {
        $this->secretKey = Config::get('services.paystack.secret_key');
        $this->baseUrl = 'https://api.paystack.co';

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }

    public function initializePayment($request)
    {
        try {
            $response = $this->client->post('/transaction/initialize', [
                'json' => [
                    'amount' => $request->total_amount * 100,
                    'email' => Auth::user()->email,
                    'reference' => $request->reference,
                    'metadata' => [
                        'custom_fields' => [
                            [
                                'user_id' => Auth::id(),
                                'reference' => $request->subscription_id ?? null,
                                'type' => $request->type
                            ]
                        ]
                    ],
                    'channels' => ['card', 'bank', 'ussd', 'bank_transfer'],
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'authorization_url' => $responseData['data']['authorization_url'],
                    'access_code' => $responseData['data']['access_code'],
                    'reference' => $responseData['data']['reference']
                ];
            }

            return $this->errorResponse('Payment initialization failed', 400);

        } catch (GuzzleException $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function verifyPayment(string $reference): array
    {
        try {

            $response = $this->client->get("/transaction/verify/{$reference}");
            $paymentDetails = json_decode($response->getBody(), true);

            if (!$paymentDetails['status'] || $paymentDetails['data']['status'] !== 'success') {
                return ['status' => 'error', 'message' => 'Payment verification failed'];
            }

            $data = $paymentDetails['data'];
            $status = strtolower($data['status']);
            $metadata = $data['metadata']['custom_fields'][0] ?? [];

            if (isset($data['authorization']['authorization_code'])) {
                // Vault::updateOrCreate(
                //     ['user_id' => $metadata['user_id'] ?? Auth::id()],
                //     [
                //         'card_token' => $data['authorization']['authorization_code'],
                //         'authorization_code' => $data['authorization']['authorization_code'],
                //         'card_type' => $data['authorization']['card_type'] ?? null,
                //         'last4' => $data['authorization']['last4'] ?? null,
                //         'exp_month' => $data['authorization']['exp_month'] ?? null,
                //         'exp_year' => $data['authorization']['exp_year'] ?? null,
                //     ]
                // );
            }

            return [
                'status' => $status === 'success' ? 'success' : 'failed',
                'message' => $paymentDetails['message'],
                'data' => $data,
                'amount' => $data['amount'] / 100, // Convert from kobo
                'paid_at' => $data['paid_at'],
                'reference' => $reference,
                'gateway_reference' => $data['reference'],
            ];
        } catch (\Exception $e) {
            Log::error('Paystack payment verification error: ' . $e->getMessage());
            throw new PaymentGatewayException(
                'Payment verification error: ' . $e->getMessage()
            );
        }
    }

    public function refundPayment(string $reference, float $amount = null)
    {
        try {
            $data = [
                'transaction' => $reference,
            ];

            if ($amount) {
                $data['amount'] = $amount * 100; // Convert to kobo
            }

            $response = $this->client->post("/refund", [
                'json' => $data
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'message' => $responseData['message'],
                    'data' => $responseData['data'],
                    'amount' => $responseData['data']['amount'] / 100,
                    'reference' => $reference,
                ];
            }

            return $this->errorResponse('Failed to process refund', 400);

        } catch (\Exception $e) {
            Log::error('Paystack refund error: ' . $e->getMessage());
            throw new PaymentGatewayException(
                'Refund processing error: ' . $e->getMessage()
            );
        }
    }

    public function cardCharge($request)
    {
        try {
            $response = $this->client->post('/charge', [
                'json' => [
                    'email' => $request->email ?? Auth::user()->email,
                    'amount' => $request->amount * 100,
                    'card' => [
                        'number' => $request->card_number,
                        'cvv' => $request->cvv,
                        'expiry_month' => $request->expiry_month,
                        'expiry_year' => $request->expiry_year,
                    ],
                    'metadata' => [
                        'custom_fields' => [
                            [
                                'user_id' => Auth::id(),
                                'subscription_id' => $request->subscription_id ?? null,
                                'type' => $request->type
                            ]
                        ]
                    ]
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {

                $status = $responseData['data']['status'] ?? '';

                return [
                    'status' => 'success',
                    'charge_status' => $status,
                    'reference' => $responseData['data']['reference'],
                    'message' =>  $responseData['message'],
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('Payment charge failed', 400, $responseData);

        } catch (GuzzleException $e) {
            Log::error('Paystack direct charge error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function bankAccountCharge(array $data)
    {
        try {
            $payload = [
                'email' => $data['email'] ?? Auth::user()->email,
                'amount' => $data['amount'] * 100, // Convert to kobo
                'metadata' => [
                    'custom_fields' => [
                        [
                            'user_id' => Auth::id(),
                            'reference' => $data['subscription_id'] ?? null,
                            'type' => $data['type'] ?? 'wallet_funding'
                        ]
                    ]
                ],
            ];

            // Handle Kuda Bank specifically which requires phone and token
            if (isset($data['bank_code']) && $data['bank_code'] === '50211') {
                $payload['bank'] = [
                    'code' => $data['bank_code'],
                    'phone' => $data['phone'],
                    'token' => $data['token']
                ];
            } else {
                // For other banks
                $payload['bank'] = [
                    'code' => $data['bank_code'],
                    'account_number' => $data['account_number']
                ];
            }

            if (isset($data['birthday'])) {
                $payload['birthday'] = $data['birthday'];
            }

            $response = $this->client->post('/charge', [
                'json' => $payload
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                // Handle different status responses
                $status = $responseData['data']['status'] ?? '';

                return [
                    'status' => 'success',
                    'charge_status' => $status,
                    'reference' => $responseData['data']['reference'],
                    'message' => $responseData['message'],
                    'display_text' => $responseData['data']['display_text'] ?? '',
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('Bank payment charge failed', 400, $responseData);

        } catch (GuzzleException $e) {
            Log::error('Paystack bank account charge error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function submitBirthday($reference, $birthday)
    {
        try {
            $response = $this->client->post('/charge/submit_birthday', [
                'json' => [
                    'reference' => $reference,
                    'birthday' => $birthday // Format: YYYY-MM-DD
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'message' => $responseData['message'],
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('Birthday submission failed', 400);

        } catch (GuzzleException $e) {
            Log::error('Paystack birthday submission error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function getBanks()
    {
        try {
            $response = $this->client->get('/bank?pay_with_bank=true&country=nigeria');
            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('Failed to fetch banks', 400);

        } catch (GuzzleException $e) {
            Log::error('Paystack fetch banks error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function submitPin($reference, $pin)
    {
        try {
            $response = $this->client->post('/charge/submit_pin', [
                'json' => [
                    'reference' => $reference,
                    'pin' => $pin
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'message' => $responseData['message'],
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('PIN submission failed', 400);

        } catch (GuzzleException $e) {
            Log::error('Paystack PIN submission error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function submitOtp($reference, $otp)
    {
        try {
            $response = $this->client->post('/charge/submit_otp', [
                'json' => [
                    'reference' => $reference,
                    'otp' => $otp
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);

            if ($responseData['status']) {
                return [
                    'status' => 'success',
                    'message' => $responseData['message'],
                    'data' => $responseData['data']
                ];
            }

            return $this->errorResponse('OTP validation failed', 400);

        } catch (GuzzleException $e) {
            Log::error('Paystack OTP submission error: ' . $e->getMessage());
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    public function assignDVTAccount($request){
        $response = $this->client->post("/dedicated_account", [
            'json' => [
                'customer' => $request['customer_id'],
                'preferred_bank' => 'wema-bank',

            ]
        ]);

        $responseData = json_decode($response->getBody(), true);

        if ($responseData['status']) {
            return [
                'status' => 'success',
                'bank' => $responseData['data']['bank'],
                'account_name' => $responseData['data']['account_name'],
                'account_number' => $responseData['data']['account_number'],
                'dvt_acc_id' => $responseData['data']['id'],
            ];
        }

        return $this->errorResponse('Dedicated account assignment failed', 400);
    }

    public function createCustomer($request){
        $response = $this->client->post("/customer", [
            'json' => [
                "email" => $request['email'],
                "first_name" => $request['firstname'],
                "last_name" => $request['lastname'],
                "phone" => $request['phone']
            ]
        ]);

        $responseData = json_decode($response->getBody(), true);

        if ($responseData['status']) {
            return [
                'status' => 'success',
                'data' => $responseData['data']
            ];
        }

        return $this->errorResponse('Customer account failed', 400);
    }
}
