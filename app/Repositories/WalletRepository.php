<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Wallet;


class WalletRepository extends BaseRepository
{
    public function __construct(Wallet $model)
    {
        parent::__construct($model);
    }

    public function findByUser(User $user): Wallet
    {
        $wallet = $this->model->where('user_id', $user->id)->first();

        if (!$wallet) {
            $wallet = $this->create([
                'user_id' => $user->id,
                'balance' => 0,
                'currency' => 'NGN',
                'is_active' => true
            ]);
        }

        return $wallet;
    }

    public function updateBalance(Wallet $wallet, float $amount): bool
    {
        $wallet->balance += $amount;
        return $wallet->save();
    }

    public function deductBalance(Wallet $wallet, float $amount): bool
    {
        if ($wallet->balance < $amount) {
            return false;
        }

        $wallet->balance -= $amount;
        return $wallet->save();
    }


    public function getBalance(User $user): float
    {
        return $user->wallet->balance;
    }

    public function getTransactionStats(User $user): array
    {
        $wallet = $user->wallet;

        return [
            'total_credit' => $wallet->transactions()
                ->where('type', 'credit')
                ->sum('amount'),
            'total_debit' => $wallet->transactions()
                ->where('type', 'debit')
                ->sum('amount'),
            'transaction_count' => $wallet->transactions()->count(),
            'last_transaction' => $wallet->transactions()
                ->latest()
                ->first()
        ];
    }
}
