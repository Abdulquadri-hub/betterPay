<?php

namespace App\DTOs;

class WalletFundingDTO
{
    public int $userId;
    public float $amount;
    public string $reference;
    public string $paymentMethod;
    public ?array $metadata;

    public function __construct(
        int $userId,
        float $amount,
        string $reference,
        string $paymentMethod,
        ?array $metadata = null
    ) {
        $this->userId = $userId;
        $this->amount = $amount;
        $this->reference = $reference;
        $this->paymentMethod = $paymentMethod;
        $this->metadata = $metadata;
    }
}
