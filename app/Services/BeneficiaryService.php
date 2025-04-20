<?php

namespace App\Services;

use App\Models\User;
use App\Models\Beneficiary;
use App\Repositories\BeneficiaryRepository;
use App\Repositories\ProviderRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class BeneficiaryService
{
    protected $beneficiaryRepository;
    protected $providerRepository;

    public function __construct(
        BeneficiaryRepository $beneficiaryRepository,
        ProviderRepository $providerRepository
    ) {
        $this->beneficiaryRepository = $beneficiaryRepository;
        $this->providerRepository = $providerRepository;
    }

    public function getUserBeneficiaries(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->beneficiaryRepository->getUserBeneficiaries($user, $filters, $perPage);
    }

    public function getBeneficiariesByServiceType(User $user, string $serviceType): Collection
    {
        return $this->beneficiaryRepository->findByCondition([
            'user_id' => $user->id,
            'service_type' => $serviceType
        ]);
    }

    public function getBeneficiary(User $user, int $beneficiaryId): ?Beneficiary
    {
        $beneficiary = $this->beneficiaryRepository->findById($beneficiaryId);

        // Check if beneficiary belongs to user
        if (!$beneficiary || $beneficiary->user_id !== $user->id) {
            return null;
        }

        return $beneficiary;
    }

    public function createBeneficiary(User $user, array $data): Beneficiary
    {
        // Check if provider exists
        if (isset($data['provider_id'])) {
            $provider = $this->providerRepository->findById($data['provider_id']);
            if (!$provider) {
                throw new \Exception('Invalid provider selected');
            }
        }

        // Create beneficiary
        return $this->beneficiaryRepository->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'identifier' => $data['identifier'], // Phone number, meter number, smart card number, etc.
            'service_type' => $data['service_type'],
            'provider_id' => $data['provider_id'] ?? null,
            'metadata' => $data['metadata'] ?? null
        ]);
    }

    public function updateBeneficiary(User $user, int $beneficiaryId, array $data): ?Beneficiary
    {
        $beneficiary = $this->getBeneficiary($user, $beneficiaryId);

        if (!$beneficiary) {
            return null;
        }

        // Check if provider exists
        if (isset($data['provider_id'])) {
            $provider = $this->providerRepository->findById($data['provider_id']);
            if (!$provider) {
                throw new \Exception('Invalid provider selected');
            }
        }

        // Update beneficiary
        $this->beneficiaryRepository->update($beneficiary, $data);

        return $beneficiary->fresh();
    }

    public function deleteBeneficiary(User $user, int $beneficiaryId): bool
    {
        $beneficiary = $this->getBeneficiary($user, $beneficiaryId);

        if (!$beneficiary) {
            return false;
        }

        return $this->beneficiaryRepository->delete($beneficiary);
    }
}
