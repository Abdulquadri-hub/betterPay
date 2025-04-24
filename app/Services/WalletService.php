<?php

namespace App\Services;

use App\DTOs\WalletFundingDTO;
use App\Events\WalletFunded;
use App\Exceptions\PaymentGatewayException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Repositories\WalletRepository;
use App\Repositories\TransactionRepository;
use App\Services\Payment\PaystackService;
use App\Services\Payment\FlutterwaveService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WalletService
{
    protected $walletRepository;
    protected $transactionRepository;
    protected $paystackService;
    protected $flutterwaveService;

    public function __construct(
        WalletRepository $walletRepository,
        TransactionRepository $transactionRepository,
        PaystackService $paystackService,
        FlutterwaveService $flutterwaveService
    ) {
        $this->walletRepository = $walletRepository;
        $this->transactionRepository = $transactionRepository;
        $this->paystackService = $paystackService;
        $this->flutterwaveService = $flutterwaveService;
    }

    public function getWalletByUser(User $user): Wallet
    {
        return $this->walletRepository->findByUser($user);
    }

    public function getWalletHistory(User $user): Collection
    {
        return $this->transactionRepository->getWalletTransactions($user->wallet);
    }

    public function createWallet($user): User
    {
        DB::beginTransaction();

        try {
            // $result = $this->paystackService->createCustomer([
            //     'firstname' => $user->firstname,
            //     'lastname' => $user->lastname,
            //     'email' => $user->email,
            //     'phone' => $user->phone,
            // ]);

            // if($result['status'] === "success"){
                // $response = $this->paystackService->assignDVTAccount([
                //     'customer_id' => $result['data']['id']
                // ]);

                // if($response['status'] === "success"){
                    $wallet = new Wallet([
                        'balance' => 0,
                        'currency' => 'NGN',
                        // 'bank' =>  $result['bank']['name'],
                        // 'account_name' =>  json_encode($result['account_name']),
                        // 'account_number' =>  json_encode($result['account_number']),
                        // 'dvt_acc_id' =>  json_encode($result['dvt_acc_id']),
                        'is_active' => true
                    ]);

                    $user->wallet()->save($wallet);
                // }
            // }

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getSupportedBanks(): array
    {
        return $this->paystackService->getBanks();
    }

    public function fundWallet(User $user, array $data): Transaction
    {
        $reference = 'PV-' . Str::random(16);
        $amount = $data['amount'];
        $paymentMethod = $data['payment_method'];
        $metadata = $data['metadata'] ?? [];

        $walletFundingDTO = new WalletFundingDTO(
            $user->id,
            $amount,
            $reference,
            $paymentMethod,
            $metadata
        );

        DB::beginTransaction();

        try {

            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'wallet_funding',
                'amount' => $amount,
                'reference' => $reference,
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'metadata' => $metadata
            ]);

            // Initialize payment gateway
            $paymentResponse = $this->initializePayment($walletFundingDTO);

            $transaction->authorization_url = $paymentResponse['authorization_url'] ?? null;
            $transaction->gateway_reference = $paymentResponse['gateway_reference'] ?? $paymentResponse['reference'] ?? null;
            $transaction->save();

            DB::commit();

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PaymentGatewayException('Failed to initialize payment: ' . $e->getMessage());
        }
    }

    public function fundWalletWithCard(User $user, array $data): array
    {
        $reference = 'PV-CARD-' . Str::random(16);
        $amount = $data['amount'];

        DB::beginTransaction();

        try {

            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'wallet_funding',
                'amount' => $amount,
                'reference' => $reference,
                'status' => 'pending',
                'payment_method' => 'paystack_card',
                'metadata' => [
                    'payment_type' => 'card'
                ]
            ]);

            $cardRequest = (object) [
                'email' => $user->email,
                'amount' => $amount,
                'card_number' => $data['card_number'],
                'cvv' => $data['cvv'],
                'expiry_month' => $data['expiry_month'],
                'expiry_year' => $data['expiry_year'],
                'type' => 'wallet_funding',
                'reference' => $reference
            ];

            $paymentResponse = $this->paystackService->cardCharge($cardRequest);

            if ($paymentResponse['status'] === 'success') {
                $transaction->gateway_reference = $paymentResponse['reference'];
                $transaction->save();

                DB::commit();

                return [
                    'status' => 'success',
                    'message' => 'Card charge initiated successfully',
                    'transaction' => $transaction,
                    'payment_data' => $paymentResponse
                ];
            }

            DB::rollBack();
            return $paymentResponse;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new PaymentGatewayException('Failed to process card payment: ' . $e->getMessage());
        }
    }


    public function fundWalletWithBank(User $user, array $data): array
    {
        $reference = 'PV-BANK-' . Str::random(16);
        $amount = $data['amount'];

        DB::beginTransaction();

        try {

            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'wallet_funding',
                'amount' => $amount,
                'reference' => $reference,
                'status' => 'pending',
                'payment_method' => 'paystack_bank',
                'metadata' => [
                    'payment_type' => 'bank',
                    'bank_code' => $data['bank_code'] ?? null
                ]
            ]);

            // Process the bank payment
            $bankData = [
                'email' => $user->email,
                'amount' => $amount,
                'bank_code' => $data['bank_code'],
                'account_number' => $data['account_number'] ?? null,
                'phone' => $data['phone'] ?? null, // For Kuda Bank
                'token' => $data['token'] ?? null, // For Kuda Bank
                'birthday' => $data['birthday'] ?? null,
                'type' => 'wallet_funding',
                'reference' => $reference
            ];

            $paymentResponse = $this->paystackService->bankAccountCharge($bankData);

            if ($paymentResponse['status'] === 'success') {
                $transaction->gateway_reference = $paymentResponse['reference'];
                $transaction->save();

                DB::commit();

                return [
                    'status' => 'success',
                    'message' => 'Bank charge initiated successfully',
                    'transaction' => $transaction,
                    'payment_data' => $paymentResponse
                ];
            }

            DB::rollBack();
            return $paymentResponse; // Error response

        } catch (\Exception $e) {
            DB::rollBack();
            throw new PaymentGatewayException('Failed to process bank payment: ' . $e->getMessage());
        }
    }

    public function submitBirthdayForBankCharge(string $reference, string $birthday): array
    {
        try {
            $transaction = $this->transactionRepository->findByReference($reference);

            if (!$transaction || $transaction->status !== 'pending') {
                return [
                    'status' => 'error',
                    'message' => 'Invalid transaction or already processed'
                ];
            }

            return $this->paystackService->submitBirthday($reference, $birthday);

        } catch (\Exception $e) {
            throw new PaymentGatewayException('Birthday submission failed: ' . $e->getMessage());
        }
    }


    public function submitOtpForTransaction(string $reference, string $otp): array
    {
        try {
            $transaction = $this->transactionRepository->findByReference($reference);

            if (!$transaction || $transaction->status !== 'pending') {
                return [
                    'status' => 'error',
                    'message' => 'Invalid transaction or already processed'
                ];
            }

            return $this->paystackService->submitOtp($reference, $otp);

        } catch (\Exception $e) {
            throw new PaymentGatewayException('OTP submission failed: ' . $e->getMessage());
        }
    }

    public function submitPinForTransaction(string $reference, string $pin): array
    {
        try {
            $transaction = $this->transactionRepository->findByReference($reference);

            if (!$transaction || $transaction->status !== 'pending') {
                return [
                    'status' => 'error',
                    'message' => 'Invalid transaction or already processed'
                ];
            }

            return $this->paystackService->submitPin($reference, $pin);

        } catch (\Exception $e) {
            throw new PaymentGatewayException('PIN submission failed: ' . $e->getMessage());
        }
    }

    protected function initializePayment(WalletFundingDTO $dto): array
    {
        switch ($dto->paymentMethod) {
            case 'paystack':
                return $this->paystackService->initializePayment((object)[
                    'total_amount' => $dto->amount,
                    'reference' => $dto->reference,
                    'type' => 'wallet_funding'
                ]);

            case 'flutterwave':
                return $this->flutterwaveService->initializePayment(
                    $dto->amount,
                    'Wallet funding',
                    $dto->reference,
                    $dto->metadata
                );

            default:
                throw new PaymentGatewayException('Unsupported payment method');
        }
    }

    public function verifyWalletFunding(User $user, string $reference): Transaction
    {
        $transaction = $this->transactionRepository->findByReference($reference);

        if (!$transaction || $transaction->user_id !== $user->id) {
            throw new \Exception('Transaction not found');
        }

        if ($transaction->status === 'completed') {
            return $transaction;
        }

        // Verify with payment gateway
        // if (in_array($transaction->payment_method, ['paystack', 'paystack_card', 'paystack_bank'])) {
            $verificationResponse = $this->paystackService->verifyPayment($transaction->gateway_reference);
        // } else {
            // $verificationResponse = $this->flutterwaveService->verifyPayment($reference);
        // }

        if ($verificationResponse['status'] === 'success') {
            $this->completeWalletFunding($transaction);
        } else {
            $transaction->status = 'failed';
            $transaction->save();
        }

        return $transaction;
    }

    protected function completeWalletFunding(Transaction $transaction): void
    {
        DB::beginTransaction();

        try {
            // Update transaction status
            $transaction->status = 'completed';
            $transaction->completed_at = now();
            $transaction->save();

            // Update wallet balance
            $wallet = $this->walletRepository->findById($transaction->user->wallet->id);
            $wallet->balance += $transaction->amount;
            $wallet->save();

            // Dispatch wallet funded event
            event(new WalletFunded($transaction));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function handlePaystackWebhook(array $payload): void
    {
        $event = $payload['event'] ?? null;

        if ($event !== 'charge.success') {
            return;
        }

        $data = $payload['data'] ?? [];
        $reference = $data['reference'] ?? null;

        if (!$reference) {
            return;
        }

        $transaction = $this->transactionRepository->findByReference($reference);

        if (!$transaction || $transaction->status !== 'pending') {
            return;
        }

        $this->completeWalletFunding($transaction);
    }
}
