<?php

namespace App\Services;

use App\DTOs\TransactionDTO;
use App\Events\TransactionInitiated;
use App\Events\TransactionCompleted;
use App\Events\TransactionFailed;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\TransactionRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;


class TransactionService
{
    protected $transactionRepository;

    public function __construct(TransactionRepository $transactionRepository)
    {
        $this->transactionRepository = $transactionRepository;
    }

    public function getUserTransactions(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->transactionRepository->getUserTransactions($user, $filters, $perPage);
    }

    public function getTransaction(User $user, int $transactionId): ?Transaction
    {
        $transaction = $this->transactionRepository->findById($transactionId);

        // Check if transaction belongs to user
        if (!$transaction || $transaction->user_id !== $user->id) {
            return null;
        }

        return $transaction;
    }

    public function getTransactionSummary(User $user): array
    {
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        $transactions = $this->transactionRepository->getUserAllTransactions($user);

        return [
            'total_count' => $transactions->count(),
            'successful_count' => $transactions->where('status', 'completed')->count(),
            'failed_count' => $transactions->where('status', 'failed')->count(),
            'pending_count' => $transactions->where('status', 'pending')->count(),
            'today_amount' => $transactions->where('status', 'completed')
                ->where('created_at', '>=', $today)
                ->sum('amount'),
            'week_amount' => $transactions->where('status', 'completed')
                ->where('created_at', '>=', $thisWeek)
                ->sum('amount'),
            'month_amount' => $transactions->where('status', 'completed')
                ->where('created_at', '>=', $thisMonth)
                ->sum('amount'),
            'all_time_amount' => $transactions->where('status', 'completed')->sum('amount')
        ];
    }

    public function getTransactionStats(User $user): array
    {
        $transactions = $this->transactionRepository->getUserAllTransactions($user);

        // Group by type
        $byType = $transactions->where('status', 'completed')
            ->groupBy('type')
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => $group->sum('amount')
                ];
            });

        // Group by month
        $byMonth = $transactions->where('status', 'completed')
            ->groupBy(function ($transaction) {
                return $transaction->created_at->format('Y-m');
            })
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'total_amount' => $group->sum('amount')
                ];
            });

        return [
            'by_type' => $byType,
            'by_month' => $byMonth
        ];
    }
}
