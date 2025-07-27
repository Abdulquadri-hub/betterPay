<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use App\Repositories\TokenRepository;
use Illuminate\Support\Facades\Cache;

class TokenService {

    private TokenRepository $tokenRepository;

    public function __construct(TokenRepository $tokenRepository)
    {
        $this->tokenRepository = $tokenRepository;
    }

    public function generateAuthToken(User $user): string
    {
        return $user->createToken('auth-token')->plainTextToken;
    }

    public function generateEmailVerificationToken(User $user): string
    {
        $token = rand(000000,999999);

        $this->tokenRepository->createEmailVerificationToken($user, $token);

        return $token;
    }

    public function generatePasswordResetToken(User $user): string
    {
        $token = rand(0000,9999);

        $this->tokenRepository->createPasswordResetToken($user, $token);

        return $token;
    }

    public function verifyEmailToken(string $token): ?User
    {
        return $this->tokenRepository->verifyEmailToken($token);
    }

    public function verifyPasswordResetToken(string $token): ?User
    {
        return $this->tokenRepository->verifyPasswordResetToken($token);
    }

    public function revokeCurrentToken($request): void
    {
        $request->user()->currentAccessToken()->delete();
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }

    public function generateIdentityVerificationToken($user, $code)
    {
        // Store the code in cache with expiration (15 minutes)
        $key = 'identity_verification_' . $user->id;

        Cache::put($key, [
            'code' => $code,
            'user_id' => $user->id,
            'created_at' => now()
        ], now()->addMinutes(15));

        return $key;
    }


    public function verifyIdentityVerificationToken($user, $providedCode)
    {
        $key = 'identity_verification_' . $user->id;
        $stored = Cache::get($key);

        if (!$stored || $stored['code'] !== $providedCode || $stored['user_id'] !== $user->id) {
            return false;
        }

        // Remove the code after successful verification
        Cache::forget($key);

        return true;
    }

}
