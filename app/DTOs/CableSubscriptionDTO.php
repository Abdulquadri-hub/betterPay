<?php

namespace App\DTOs;

class CableSubscriptionDTO
{
    public int $userId;
    public int $providerId;
    public string $smartCardNumber;
    public int $packageId;
    public float $amount;
    public string $reference;
    public bool $saveBeneficiary;
    public ?array $metadata;

    public function __construct(
        int $userId,
        int $providerId,
        string $smartCardNumber,
        int $packageId,
        float $amount,
        string $reference,
        bool $saveBeneficiary = false,
        ?array $metadata = null
    ) {
        $this->userId = $userId;
        $this->providerId = $providerId;
        $this->smartCardNumber = $smartCardNumber;
        $this->packageId = $packageId;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->saveBeneficiary = $saveBeneficiary;
        $this->metadata = $metadata;
    }

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'type' => 'cable_subscription',
            'provider_id' => $this->providerId,
            'recipient' => $this->smartCardNumber,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'metadata' => array_merge($this->metadata ?? [], [
                'package_id' => $this->packageId,
                'save_beneficiary' => $this->saveBeneficiary
            ]),
            'status' => 'pending'
        ];
    }
}
