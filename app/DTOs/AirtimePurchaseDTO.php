<?php

namespace App\DTOs;

class AirtimePurchaseDTO
{
    public int $userId;
    public int $providerId;
    public string $phoneNumber;
    public float $amount;
    public string $reference;
    public bool $saveBeneficiary;

    public function __construct(
        int $userId,
        int $providerId,
        string $phoneNumber,
        float $amount,
        string $reference,
        bool $saveBeneficiary = false
    ) {
        $this->userId = $userId;
        $this->providerId = $providerId;
        $this->phoneNumber = $phoneNumber;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->saveBeneficiary = $saveBeneficiary;
    }
}
