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
            $result = $this->paystackService->createCustomer([
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'phone' => $user->phone,
            ]);

            if($result['status'] === "success"){
                $response = $this->paystackService->assignDVTAccount([
                    'customer_id' => $result['data']['id']
                ]);

                if($response['status'] === "success"){
                    $wallet = new Wallet([
                        'balance' => 0,
                        'currency' => 'NGN',
                        'bank' =>  $result['bank']['name'],
                        'account_name' =>  json_encode($result['account_name']),
                        'account_number' =>  json_encode($result['account_number']),
                        'dvt_acc_id' =>  json_encode($result['dvt_acc_id']),
                        'is_active' => true
                    ]);

                    $user->wallet()->save($wallet);
                }
            }

            DB::commit();
            return $user;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
                'metadata' => $metadata
            ]);

            // Initialize payment gateway
            $paymentResponse = $this->initializePayment($walletFundingDTO);

            $transaction->authorization_url = $paymentResponse['authorization_url'] ?? null;
            $transaction->gateway_reference = $paymentResponse['gateway_reference'] ?? null;
            $transaction->save();

            DB::commit();

            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PaymentGatewayException('Failed to initialize payment: ' . $e->getMessage());
        }
    }

    protected function initializePayment(WalletFundingDTO $dto): array
    {
        switch ($dto->paymentMethod) {
            case 'paystack':
                return $this->paystackService->initializePayment(
                    $dto->amount,
                    'Wallet funding',
                    $dto->reference,
                    $dto->metadata
                );

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
        if ($transaction->payment_method === 'paystack') {
            $verificationResponse = $this->paystackService->verifyPayment($reference);
        } else {
            $verificationResponse = $this->flutterwaveService->verifyPayment($reference);
        }

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
            $wallet = $this->walletRepository->findById($transaction->user->wallet_id);
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
