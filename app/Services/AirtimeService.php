<?php

namespace App\Services;

use App\DTOs\AirtimePurchaseDTO;
use App\Events\TransactionInitiated;
use App\Events\TransactionCompleted;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\VTUApiException;
use App\Models\User;
use App\Repositories\ProviderRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\WalletRepository;
use App\Services\External\VTUProviderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AirtimeService
{
    protected $providerRepository;
    protected $transactionRepository;
    protected $walletRepository;
    protected $vtuProviderService;

    public function __construct(
        ProviderRepository $providerRepository,
        TransactionRepository $transactionRepository,
        WalletRepository $walletRepository,
        VTUProviderService $vtuProviderService
    ) {
        $this->providerRepository = $providerRepository;
        $this->transactionRepository = $transactionRepository;
        $this->walletRepository = $walletRepository;
        $this->vtuProviderService = $vtuProviderService;
    }

    public function getProviders()
    {
        return $this->providerRepository->getByType('airtime');
    }

    public function purchaseAirtime(User $user, array $data)
    {
        // Validate if provider exists
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'airtime') {
            throw new \Exception('Invalid provider selected');
        }

        // Check if user has sufficient balance
        $wallet = $this->walletRepository->findByUser($user);
        if ($wallet->balance < $data['amount']) {
            throw new InsufficientBalanceException('Insufficient wallet balance');
        }

        // Generate reference
        $reference = 'PV-AIR-' . Str::random(12);

        // Prepare DTO
        $airtimeDTO = new AirtimePurchaseDTO(
            $user->id,
            $data['provider_id'],
            $data['phone_number'],
            $data['amount'],
            $reference,
            $data['save_beneficiary'] ?? false
        );

        // Begin transaction
        DB::beginTransaction();

        try {
            // Create transaction record
            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'airtime_purchase',
                'amount' => $data['amount'],
                'reference' => $reference,
                'provider' => $provider->name,
                'recipient' => $data['phone_number'],
                'status' => 'pending',
                'metadata' => [
                    'provider_id' => $provider->id,
                    'phone_number' => $data['phone_number'],
                    'save_beneficiary' => $data['save_beneficiary'] ?? false
                ]
            ]);

            // Dispatch transaction initiated event
            event(new TransactionInitiated($transaction));

            // Deduct from wallet
            $wallet->balance -= $data['amount'];
            $wallet->save();

            // Process with VTU provider
            $vtuResponse = $this->vtuProviderService->purchaseAirtime(
                $provider->provider_code,
                $data['phone_number'],
                $data['amount'],
                $reference
            );

            // Save beneficiary if requested
            if ($data['save_beneficiary'] ?? false) {
                $this->saveBeneficiary($user, $data['phone_number'], $provider->id, 'airtime');
            }

            // Update transaction with provider response
            $transaction->status = $vtuResponse['success'] ? 'completed' : 'failed';
            $transaction->provider_response = $vtuResponse;
            $transaction->completed_at = now();
            $transaction->save();

            // If failed, refund wallet
            if (!$vtuResponse['success']) {
                $wallet->balance += $data['amount'];
                $wallet->save();
                DB::commit();
                throw new VTUApiException($vtuResponse['message'] ?? 'VTU provider error occurred');
            }

            // Dispatch transaction completed event
            event(new TransactionCompleted($transaction));

            DB::commit();
            return $transaction;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function saveBeneficiary(User $user, string $phoneNumber, int $providerId, string $serviceType)
    {
        // Check if beneficiary already exists
        $existingBeneficiary = $user->beneficiaries()
            ->where('phone_number', $phoneNumber)
            ->where('service_type', $serviceType)
            ->first();

        if (!$existingBeneficiary) {
            // Create new beneficiary
            $user->beneficiaries()->create([
                'name' => "Beneficiary for $phoneNumber",
                'phone_number' => $phoneNumber,
                'provider_id' => $providerId,
                'service_type' => $serviceType
            ]);
        }
    }
}
