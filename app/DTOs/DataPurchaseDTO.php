<?php

namespace App\DTOs;

class DataPurchaseDTO
{
    public int $userId;
    public int $providerId;
    public int $packageId;
    public string $phoneNumber;
    public float $amount;
    public string $reference;
    public bool $saveBeneficiary;

    public function __construct(
        int $userId,
        int $providerId,
        int $packageId,
        string $phoneNumber,
        float $amount,
        string $reference,
        bool $saveBeneficiary = false
    ) {
        $this->userId = $userId;
        $this->providerId = $providerId;
        $this->packageId = $packageId;
        $this->phoneNumber = $phoneNumber;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->saveBeneficiary = $saveBeneficiary;
    }
}
