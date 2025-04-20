<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Beneficiary;
use Illuminate\Database\Eloquent\Collection;


class BeneficiaryRepository extends BaseRepository
{
    public function __construct(Beneficiary $model)
    {
        parent::__construct($model);
    }

    public function getUserBeneficiaries(User $user, ?string $serviceType = null): Collection
    {
        $query = $this->model->where('user_id', $user->id);

        if ($serviceType) {
            $query->where('service_type', $serviceType);
        }

        return $query->orderBy('name')
                    ->get();
    }

    public function getBeneficiariesByService(User $user, string $serviceType): Collection
    {
        return $this->model->where('user_id', $user->id)
                          ->where('service_type', $serviceType)
                          ->orderBy('name')
                          ->get();
    }

    public function findByIdentifier(User $user, string $serviceType, string $identifier): ?Beneficiary
    {
        return $this->model->where('user_id', $user->id)
                          ->where('service_type', $serviceType)
                          ->where('identifier', $identifier)
                          ->first();
    }

    public function createBeneficiary(array $data): Beneficiary
    {
        // Check if beneficiary already exists
        $existing = $this->findByIdentifier(
            User::find($data['user_id']),
            $data['service_type'],
            $data['identifier']
        );

        if ($existing) {
            return $existing;
        }

        return $this->create($data);
    }
}
