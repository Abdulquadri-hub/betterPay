<?php

namespace App\Services;

use App\DTOs\CableSubscriptionDTO;
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

class CableService
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
        return $this->providerRepository->getByType('cable');
    }

    public function getPackages(int $providerId)
    {
        $provider = $this->providerRepository->findById($providerId);
        if (!$provider || $provider->type !== 'cable') {
            throw new \Exception('Invalid provider selected');
        }

        return $provider->packages;
    }

    public function verifySmartCard(array $data)
    {
        // Validate if provider exists
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'cable') {
            throw new \Exception('Invalid provider selected');
        }

        return $this->vtuProviderService->verifySmartCard(
            $provider->provider_code,
            $data['smart_card_number']
        );
    }

    public function subscribeCable(User $user, array $data)
    {
        // Validate if provider and package exist
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'cable') {
            throw new \Exception('Invalid provider selected');
        }

        $package = $provider->packages()->find($data['package_id']);
        if (!$package) {
            throw new \Exception('Invalid cable package selected');
        }

        // Check if user has sufficient balance
        $wallet = $this->walletRepository->findByUser($user);
        if ($wallet->balance < $package->price) {
            throw new InsufficientBalanceException('Insufficient wallet balance');
        }

        // Generate reference
        $reference = 'PV-CABLE-' . Str::random(12);

        // Prepare DTO
        $cableDTO = new CableSubscriptionDTO(
            $user->id,
            $data['provider_id'],
            $data['package_id'],
            $data['smart_card_number'],
            $data['customer_name'],
            $package->price,
            $reference,
            $data['phone_number'],
            $data['save_beneficiary'] ?? false
        );

        // Begin transaction
        DB::beginTransaction();

        try {
            // Create transaction record
            $transaction = $this->transactionRepository->create([
                'user_id' => $user->id,
                'type' => 'cable_subscription',
                'amount' => $package->price,
                'reference' => $reference,
                'provider' => $provider->name,
                'recipient' => $data['smart_card_number'],
                'status' => 'pending',
                'metadata' => [
                    'provider_id' => $provider->id,
                    'package_id' => $package->id,
                    'package_name' => $package->name,
                    'smart_card_number' => $data['smart_card_number'],
                    'customer_name' => $data['customer_name'],
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
            $vtuResponse = $this->vtuProviderService->subscribeCable(
                $provider->provider_code,
                $data['smart_card_number'],
                $package->provider_code,
                $data['phone_number'],
                $reference
            );

            // Save beneficiary if requested
            if ($data['save_beneficiary'] ?? false) {
                $this->saveBeneficiary($user, $data['smart_card_number'], $provider->id, 'cable');
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

    protected function saveBeneficiary(User $user, string $smartCardNumber, int $providerId, string $serviceType)
    {
        return $user->beneficiaries()->firstOrCreate([
            'identifier' => $smartCardNumber,
            'provider_id' => $providerId,
            'service_type' => $serviceType,
        ], [
            'name' => 'Beneficiary for ' . $smartCardNumber,
        ]);
    }
}
