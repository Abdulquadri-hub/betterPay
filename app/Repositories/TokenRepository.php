<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\DB;


class TokenRepository  {

    public function createEmailVerificationToken(User $user, string $token): void
    {
        DB::table('email_verification_tokens')->insert([
            'user_id' => $user->id,
            'token' =>  $token,
            'expires_at' => now()->addHours(24),
            'created_at' => now()
        ]);
    }

    public function createPasswordResetToken(User $user, string $token): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => $token,
                'expires_at' => now()->addHours(1),
                'created_at' => now()
            ]
        );
    }

    public function verifyEmailToken(string $token): ?User
    {
        $record = DB::table('email_verification_tokens')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        DB::table('email_verification_tokens')
            ->where('user_id', $record->user_id)
            ->delete();

        return User::find($record->user_id);
    }

    public function verifyPasswordResetToken(string $token): ?User
    {
        $record = DB::table('password_reset_tokens')
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();

        if (!$record) {
            return null;
        }

        return User::where('email', $record->email)->first();
    }
}
