<?php

namespace App\DTOs;

class ElectricityPaymentDTO
{
    public int $userId;
    public int $providerId;
    public string $meterNumber;
    public string $meterType;
    public string $customerName;
    public float $amount;
    public string $reference;
    public string $phoneNumber;
    public bool $saveBeneficiary;

    public function __construct(
        int $userId,
        int $providerId,
        string $meterNumber,
        string $meterType,
        string $customerName,
        float $amount,
        string $reference,
        string $phoneNumber,
        bool $saveBeneficiary = false
    ) {
        $this->userId = $userId;
        $this->providerId = $providerId;
        $this->meterNumber = $meterNumber;
        $this->meterType = $meterType;
        $this->customerName = $customerName;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->phoneNumber = $phoneNumber;
        $this->saveBeneficiary = $saveBeneficiary;
    }
}
