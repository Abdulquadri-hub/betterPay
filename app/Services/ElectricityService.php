<?php

namespace App\Services;

use App\DTOs\ElectricityPaymentDTO;
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

class ElectricityService
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
        return $this->providerRepository->getByType('electricity');
    }

    public function verifyMeter(array $data)
    {
        // Validate if provider exists
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'electricity') {
            throw new \Exception('Invalid provider selected');
        }

        // Verify meter with VTU provider
        $verificationResponse = $this->vtuProviderService->verifyMeter(
            $provider->provider_code,
            $data['meter_number'],
            $data['meter_type'] ?? 'prepaid'
        );

        if (!$verificationResponse['success']) {
            throw new VTUApiException($verificationResponse['message'] ?? 'Failed to verify meter');
        }

        return $verificationResponse['data'];
    }

    public function payElectricity(User $user, array $data)
    {
        // Validate if provider exists
        $provider = $this->providerRepository->findById($data['provider_id']);
        if (!$provider || $provider->type !== 'electricity') {
            throw new \Exception('Invalid provider selected');
        }

        // Check if user has sufficient balance
        $wallet = $this->walletRepository->findByUser($user);
        if ($wallet->balance < $data['amount']) {
            throw new InsufficientBalanceException('Insufficient wallet balance');
        }

        // Generate reference
        $reference = 'PV-ELEC-' . Str::random(12);

        // Prepare DTO
        $electricityDTO = new ElectricityPaymentDTO(
            $user->id,
            $data['provider_id'],
            $data['meter_number'],
            $data['meter_type'] ?? 'prepaid',
            $data['customer_name'],
            $data['amount'],
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
                'type' => 'electricity_payment',
                'amount' => $data['amount'],
                'reference' => $reference,
                'provider' => $provider->name,
                'recipient' => $data['meter_number'],
                'status' => 'pending',
                'metadata' => [
                    'provider_id' => $provider->id,
                    'meter_number' => $data['meter_number'],
                    'meter_type' => $data['meter_type'] ?? 'prepaid',
                    'customer_name' => $data['customer_name'],
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
            $vtuResponse = $this->vtuProviderService->payElectricity(
                $provider->provider_code,
                $data['meter_number'],
                $data['meter_type'] ?? 'prepaid',
                $data['amount'],
                $data['phone_number'],
                $reference
            );

            // Save beneficiary if requested
            if ($data['save_beneficiary'] ?? false) {
                $this->saveBeneficiary(
                    $user,
                    $data['meter_number'],
                    $provider->id,
                    'electricity',
                    $data['customer_name'],
                    [
                        'meter_type' => $data['meter_type'] ?? 'prepaid',
                        'phone_number' => $data['phone_number']
                    ]
                );
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

    protected function saveBeneficiary(User $user, string $meterNumber, int $providerId, string $serviceType, string $name, array $metadata = [])
    {
        // Check if beneficiary already exists
        $existingBeneficiary = $user->beneficiaries()
            ->where('account_number', $meterNumber)
            ->where('service_type', $serviceType)
            ->first();

        if (!$existingBeneficiary) {
            // Create new beneficiary
            $user->beneficiaries()->create([
                'name' => $name,
                'provider_id' => $providerId,
                'service_type' => $serviceType,
                'account_number' => $meterNumber,
                'metadata' => $metadata
            ]);
        }
    }
}
