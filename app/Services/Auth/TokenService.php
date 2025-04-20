<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use App\Repositories\TokenRepository;

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
        $token = rand(0000,9999);

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

}
