<?php

namespace App\Services;

use App\DTOs\DataPurchaseDTO;
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

class DataService
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
        return $this->providerRepository->getByType('data');
    }

    public function getPackages(int $providerId)
    {
        $provider = $this->providerRepository->findById($providerId);
        if (!$provider || $provider->type !== 'data') {
            throw new \Exception('Invalid provider selected');
        }

        return $provider->packages()->where('active', true)->get();
    }

    public function purchaseData(User $user, array $data)
    {
        // Validate if provider exists
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'data') {
            throw new \Exception('Invalid provider selected');
        }

        // Validate package
        $package = $provider->packages()->findOrFail($data['package_id']);
        if (!$package->active) {
            throw new \Exception('Selected data package is not available');
        }

        // Check if user has sufficient balance
        $wallet = $this->walletRepository->findByUser($user);
        if ($wallet->balance < $package->price) {
            throw new InsufficientBalanceException('Insufficient wallet balance');
        }

        // Generate reference
        $reference = 'PV-DATA-' . Str::random(12);

        // Prepare DTO
        $dataDTO = new DataPurchaseDTO(
            $user->id,
            $data['provider_id'],
            $data['package_id'],
            $data['phone_number'],
            $package->price,
            $reference,
            $data['save_beneficiary'] ?? false
        );

        // Begin transaction
        DB::beginTransaction();

        try {
            // Create transaction record
            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'data_purchase',
                'amount' => $package->price,
                'reference' => $reference,
                'provider' => $provider->name,
                'recipient' => $data['phone_number'],
                'status' => 'pending',
                'metadata' => [
                    'provider_id' => $provider->id,
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'phone_number' => $data['phone_number'],
                    'save_beneficiary' => $data['save_beneficiary'] ?? false
                ]
            ]);

            // Dispatch transaction initiated event
            event(new TransactionInitiated($transaction));

            // Deduct from wallet
            $wallet->balance -= $package->price;
            $wallet->save();

            // Process with VTU provider
            $vtuResponse = $this->vtuProviderService->purchaseData(
                $provider->provider_code,
                $data['phone_number'],
                $package->provider_code,
                $reference
            );

            // Save beneficiary if requested
            if ($data['save_beneficiary'] ?? false) {
                $this->saveBeneficiary($user, $data['phone_number'], $provider->id, 'data');
            }

            // Update transaction with provider response
            $transaction->status = $vtuResponse['success'] ? 'completed' : 'failed';
            $transaction->provider_response = $vtuResponse;
            $transaction->completed_at = now();
            $transaction->save();

            // If failed, refund wallet
            if (!$vtuResponse['success']) {
                $wallet->balance += $package->price;
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
