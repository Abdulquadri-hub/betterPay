<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\PaymentGatewayException;
use App\Http\Resources\WalletResource;
use App\Http\Resources\TransactionResource;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function index()
    {
        $user = Auth::user();
        $wallet = $this->walletService->getWalletByUser($user);

        return response()->json([
            'status' => 'success',
            'data' => new WalletResource($wallet)
        ]);
    }

    public function history()
    {
        $user = Auth::user();
        $transactions = $this->walletService->getWalletHistory($user);

        return response()->json([
            'status' => 'success',
            'data' => TransactionResource::collection($transactions)
        ]);
    }

    public function fund(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100',
            'payment_method' => 'required|string|in:paystack,flutterwave'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $user = Auth::user();
            $transaction = $this->walletService->fundWallet($user, $request->all());

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet funding initiated successfully',
                'data' => [
                    'transaction' => new TransactionResource($transaction),
                    'authorization_url' => $transaction->authorization_url
                ]
            ]);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function fundWithCard(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100',
            'card_number' => 'required|string|min:15|max:16',
            'cvv' => 'required|string|size:3',
            'expiry_month' => 'required|string|size:2',
            'expiry_year' => 'required|string|size:2',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $user = Auth::user();
            $result = $this->walletService->fundWalletWithCard($user, $request->all());

            if ($result['status'] === 'success') {
                return response()->json([
                    'status' => 'success',
                    'message' => $result['message'],
                    'data' => [
                        'transaction' => new TransactionResource($result['transaction']),
                        'payment_data' => $result['payment_data']
                    ]
                ]);
            }

            return response()->json($result, 400);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function fundWithBank(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100',
            'bank_code' => 'required|string',
            'account_number' => 'required_without:phone|string|nullable',
            'phone' => 'required_without:account_number|string|nullable',
            'token' => 'required_with:phone|string|nullable',
            'birthday' => 'nullable|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $user = Auth::user();
            $result = $this->walletService->fundWalletWithBank($user, $request->all());

            if ($result['status'] === 'success') {
                return response()->json([
                    'status' => 'success',
                    'message' => $result['message'],
                    'data' => [
                        'transaction' => new TransactionResource($result['transaction']),
                        'payment_data' => $result['payment_data']
                    ]
                ]);
            }

            return response()->json($result, 400);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function submitOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string',
            'otp' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $result = $this->walletService->submitOtpForTransaction(
                $request->reference,
                $request->otp
            );

            return response()->json($result);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function submitPin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string',
            'pin' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $result = $this->walletService->submitPinForTransaction(
                $request->reference,
                $request->pin
            );

            return response()->json($result);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function submitBirthday(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string',
            'birthday' => 'required|date_format:Y-m-d'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $result = $this->walletService->submitBirthdayForBankCharge(
                $request->reference,
                $request->birthday
            );

            return response()->json($result);
        } catch (PaymentGatewayException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function verifyPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reference' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first()
            ], 422);
        }

        try {
            $user = Auth::user();
            $transaction = $this->walletService->verifyWalletFunding($user, $request->reference);

            return response()->json([
                'status' => 'success',
                'message' => $transaction->status === 'completed'
                    ? 'Payment verified successfully'
                    : 'Payment verification failed',
                'data' => new TransactionResource($transaction)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function getBanks()
    {
        try {
            $banks = $this->walletService->getSupportedBanks();

            return response()->json($banks);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }

    public function handleWebhook(Request $request)
    {
        // Verify webhook signature if available

        try {
            $this->walletService->handlePaystackWebhook($request->all());
            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
