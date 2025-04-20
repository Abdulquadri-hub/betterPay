<?php

namespace App\Repositories;

use App\Models\User;
use App\Models\ScheduledPayment;
use Illuminate\Database\Eloquent\Collection;

class ScheduledPaymentRepository extends BaseRepository
{
    public function __construct(ScheduledPayment $model)
    {
        parent::__construct($model);
    }

    public function getUserScheduledPayments(User $user): Collection
    {
        return $this->model->where('user_id', $user->id)
                          ->orderBy('next_payment_date')
                          ->get();
    }

    public function getActiveScheduledPayments(): Collection
    {
        return $this->model->where('is_active', true)
                          ->where('next_payment_date', '<=', now())
                          ->get();
    }

    public function getUserActiveScheduledPayments(User $user): Collection
    {
        return $this->model->where('user_id', $user->id)
                          ->where('is_active', true)
                          ->orderBy('next_payment_date')
                          ->get();
    }

    public function togglePaymentStatus(int $paymentId, bool $status): bool
    {
        $payment = $this->findById($paymentId);

        if (!$payment) {
            return false;
        }

        $payment->is_active = $status;
        return $payment->save();
    }

    public function updateNextPaymentDate(ScheduledPayment $payment): bool
    {
        // Calculate next payment date based on frequency
        $nextDate = now();

        switch ($payment->frequency) {
            case 'daily':
                $nextDate = $nextDate->addDay();
                break;
            case 'weekly':
                $nextDate = $nextDate->addWeek();
                break;
            case 'biweekly':
                $nextDate = $nextDate->addWeeks(2);
                break;
            case 'monthly':
                $nextDate = $nextDate->addMonth();
                break;
            case 'quarterly':
                $nextDate = $nextDate->addMonths(3);
                break;
            case 'yearly':
                $nextDate = $nextDate->addYear();
                break;
        }

        $payment->next_payment_date = $nextDate;
        $payment->last_processed_at = now();

        return $payment->save();
    }
}
