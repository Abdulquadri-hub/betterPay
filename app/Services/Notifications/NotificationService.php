<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Str;
use App\Notifications\PinChangedNotification;
use App\Notifications\VerifyEmailNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\PasswordChangedNotification;

class NotificationService
{
    public function sendEmailVerification(User $user, string $token): void
    {
        $user->notify(new VerifyEmailNotification($token));
    }

    public function sendPasswordReset(User $user, string $token): void
    {
        $user->notify(new ResetPasswordNotification($token));
    }

    public function sendPasswordChangeNotification(User $user): void
    {
        $user->notify(new PasswordChangedNotification($user));
    }

    public function sendPinChangeNotification(User $user)
    {
        $user->notify(new PinChangedNotification($user));
    }
}
