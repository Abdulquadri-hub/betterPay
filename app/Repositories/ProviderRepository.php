<?php

namespace App\Repositories;

use App\Models\Provider;
use App\Models\ServicePackage;
use Illuminate\Database\Eloquent\Collection;

class ProviderRepository extends BaseRepository
{
    public function __construct(Provider $model)
    {
        parent::__construct($model);
    }

    public function getByType(string $type): Collection
    {
        return $this->model->where('type', $type)
                          ->where('is_active', true)
                          ->orderBy('name')
                          ->get();
    }

    public function findByCode(string $code): ?Provider
    {
        return $this->model->where('provider_code', $code)->first();
    }

    public function getProviderPackages(int $providerId): Collection
    {
        return ServicePackage::where('provider_id', $providerId)
                             ->where('is_active', true)
                             ->orderBy('name')
                             ->get();
    }

    public function findPackageById(int $packageId): ?ServicePackage
    {
        return ServicePackage::find($packageId);
    }

    public function getDataPackagesByNetwork(int $providerId): Collection
    {
        return ServicePackage::where('provider_id', $providerId)
                             ->where('type', 'data')
                             ->where('is_active', true)
                             ->orderBy('price')
                             ->get();
    }

    public function getCablePackagesByProvider(int $providerId): Collection
    {
        return ServicePackage::where('provider_id', $providerId)
                             ->where('type', 'cable')
                             ->where('is_active', true)
                             ->orderBy('name')
                             ->get();
    }
}

