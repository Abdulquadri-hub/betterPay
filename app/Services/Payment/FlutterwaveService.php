<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FlutterwaveService
{
    protected $secretKey;
    protected $baseUrl;
    protected $redirectUrl;

    public function __construct()
    {
        $this->secretKey = config('services.flutterwave.secret_key');
        $this->baseUrl = 'https://api.flutterwave.com/v3';
        $this->redirectUrl = config('services.flutterwave.redirect_url');
    }

    public function initializePayment(
        float $amount,
        string $description,
        string $reference,
        array $metadata = []
    ): array {
        try {
            $user = auth()->user();

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/payments', [
                'tx_ref' => $reference,
                'amount' => $amount,
                'currency' => 'NGN',
                'redirect_url' => $this->redirectUrl,
                'customer' => [
                    'email' => $user->email,
                    'phone_number' => $user->phone,
                    'name' => $user->name,
                ],
                'customizations' => [
                    'title' => 'PayVista',
                    'description' => 'Wallet funding',
                    'logo' => config('services.flutterwave.logo_url')
                ],
                'meta' => array_merge($metadata, [
                    'reference' => $reference,
                    'user_id' => $user->id
                ])
            ]);

            $result = $response->json();

            if (!$response->successful() || $result['status'] !== 'success') {
                throw new PaymentGatewayException(
                    $result['message'] ?? 'Failed to initialize payment'
                );
            }

            return [
                'authorization_url' => $result['data']['link'],
                'reference' => $reference,
                'gateway_reference' => $result['data']['id'],
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave payment initialization error: ' . $e->getMessage());
            throw new PaymentGatewayException(
                'Payment gateway error: ' . $e->getMessage()
            );
        }
    }

    public function verifyPayment(string $reference): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->get($this->baseUrl . '/transactions/verify_by_reference?tx_ref=' . $reference);

            $result = $response->json();

            if (!$response->successful() || $result['status'] !== 'success') {
                throw new PaymentGatewayException(
                    $result['message'] ?? 'Failed to verify payment'
                );
            }

            $data = $result['data'];
            $status = strtolower($data['status']);

            return [
                'status' => $status === 'successful' ? 'success' : 'failed',
                'message' => $result['message'],
                'data' => $data,
                'amount' => $data['amount'],
                'paid_at' => $data['created_at'],
                'reference' => $reference,
                'gateway_reference' => $data['id'],
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave payment verification error: ' . $e->getMessage());
            throw new PaymentGatewayException(
                'Payment verification error: ' . $e->getMessage()
            );
        }
    }

    public function refundPayment(string $transactionId, float $amount, string $reason): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/transactions/' . $transactionId . '/refund', [
                'amount' => $amount,
                'reason' => $reason
            ]);

            $result = $response->json();

            if (!$response->successful() || $result['status'] !== 'success') {
                throw new PaymentGatewayException(
                    $result['message'] ?? 'Failed to process refund'
                );
            }

            return [
                'status' => 'success',
                'message' => $result['message'],
                'data' => $result['data'],
                'amount' => $result['data']['amount'],
                'transaction_id' => $transactionId,
            ];
        } catch (\Exception $e) {
            Log::error('Flutterwave refund error: ' . $e->getMessage());
            throw new PaymentGatewayException(
                'Refund processing error: ' . $e->getMessage()
            );
        }
    }
}
