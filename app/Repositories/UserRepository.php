<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository extends BaseRepository
{
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->model->where('email', $email)->first();
    }

    public function findByPhone(string $phone): ?User
    {
        return $this->model->where('phone', $phone)->first();
    }

    public function updatePassword(User $user, string $password): bool
    {
        return $user->update(['password' => $password]);
    }

    public function getUsersWithScheduledPayments(): Collection
    {
        return $this->model->whereHas('scheduledPayments', function ($query) {
            $query->where('is_active', true)
                  ->where('next_payment_date', '<=', now());
        })->get();
    }
}
