<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Hash;
use App\Contracts\Auth\HashServiceInterface;

class HashService {

    public function make(string $value): string
    {
        return Hash::make($value);
    }

    public function verify(string $value, string $hashedValue): bool
    {
        return Hash::check($value, $hashedValue);
    }

}
