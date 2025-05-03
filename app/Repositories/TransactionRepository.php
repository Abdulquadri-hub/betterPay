<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TransactionRepository extends BaseRepository
{
    public function __construct(Transaction $model)
    {
        parent::__construct($model);
    }

    public function findByReference(string $reference): ?Transaction
    {
        return $this->model->where('reference', $reference)->first();
    }

    public function findBygateWayReference(string $reference): ?Transaction
    {
        return $this->model->where('gateway_reference', $reference)->first();
    }

    public function getWalletTransactions(Wallet $wallet): Collection
    {
        return $this->model->where('user_id', $wallet->user_id)
                          ->where('type', 'wallet_funding')
                          ->orderBy('created_at', 'desc')
                          ->get();
    }

    public function getUserTransactions(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->where('user_id', $user->id);

        // Apply filters
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->orderBy('created_at', 'desc')
                    ->paginate($filters['per_page'] ?? 15);
    }

    public function getServiceTransactions(User $user, string $type): Collection
    {
        return $this->model->where('user_id', $user->id)
                          ->where('type', $type)
                          ->orderBy('created_at', 'desc')
                          ->get();
    }

    public function getRecentServiceTransactions(User $user, string $type, int $limit = 5): Collection
    {
        return $this->model->where('user_id', $user->id)
                          ->where('type', $type)
                          ->orderBy('created_at', 'desc')
                          ->limit($limit)
                          ->get();
    }

    public function countSuccessfulTransactions(User $user): int
    {
        return $this->model->where('user_id', $user->id)
                          ->where('status', 'completed')
                          ->count();
    }

    public function getTransactionStatsByPeriod(User $user, string $period): array
    {
        $query = $this->model->where('user_id', $user->id)
                            ->where('status', 'completed');

        switch ($period) {
            case 'today':
                $query->whereDate('created_at', today());
                break;
            case 'week':
                $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                break;
            case 'month':
                $query->whereMonth('created_at', now()->month)
                      ->whereYear('created_at', now()->year);
                break;
            case 'year':
                $query->whereYear('created_at', now()->year);
                break;
        }

        $transactions = $query->get();

        $stats = [
            'count' => $transactions->count(),
            'total_amount' => $transactions->sum('amount'),
            'by_type' => []
        ];

        // Group by type
        $byType = $transactions->groupBy('type');
        foreach ($byType as $type => $items) {
            $stats['by_type'][$type] = [
                'count' => $items->count(),
                'total_amount' => $items->sum('amount')
            ];
        }

        return $stats;
    }
}
