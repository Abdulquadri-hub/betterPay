<?php

namespace App\Services;

use App\Events\ScheduledPaymentFailed;
use App\Events\ScheduledPaymentProcessed;
use App\Models\ScheduledPayment;
use App\Models\User;
use App\Repositories\ScheduledPaymentRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScheduledPaymentService
{
    protected $scheduledPaymentRepository;
    protected $airtimeService;
    protected $dataService;
    protected $cableService;
    protected $electricityService;

    public function __construct(
        ScheduledPaymentRepository $scheduledPaymentRepository,
        AirtimeService $airtimeService,
        DataService $dataService,
        CableService $cableService,
        ElectricityService $electricityService
    ) {
        $this->scheduledPaymentRepository = $scheduledPaymentRepository;
        $this->airtimeService = $airtimeService;
        $this->dataService = $dataService;
        $this->cableService = $cableService;
        $this->electricityService = $electricityService;
    }

    public function getAllByUser(User $user)
    {
        return $this->scheduledPaymentRepository->getAllByUser($user);
    }

    public function getById(int $id, User $user)
    {
        return $this->scheduledPaymentRepository->getByIdAndUser($id, $user);
    }

    public function store(User $user, array $data)
    {
        // Validate frequency and start date
        $this->validateScheduleData($data);

        // Create scheduled payment
        $paymentData = array_merge($data, [
            'user_id' => $user->id,
            'status' => 'active',
            'next_payment_date' => $data['start_date']
        ]);

        return $this->scheduledPaymentRepository->create($paymentData);
    }

    public function update(ScheduledPayment $scheduledPayment, array $data)
    {
        // Validate frequency and dates if provided
        if (isset($data['frequency']) || isset($data['start_date'])) {
            $this->validateScheduleData($data);
        }

        // Update the payment
        $this->scheduledPaymentRepository->update($scheduledPayment, $data);

        // If the payment is active and the schedule changed, recalculate next payment
        if ($scheduledPayment->status === 'active' &&
            (isset($data['frequency']) || isset($data['start_date']))) {
            $this->recalculateNextPaymentDate($scheduledPayment);
        }

        return $scheduledPayment->fresh();
    }

    public function toggle(ScheduledPayment $scheduledPayment)
    {
        $newStatus = $scheduledPayment->status === 'active' ? 'paused' : 'active';

        $this->scheduledPaymentRepository->update($scheduledPayment, [
            'status' => $newStatus
        ]);

        return $scheduledPayment->fresh();
    }

    public function delete(ScheduledPayment $scheduledPayment)
    {
        return $this->scheduledPaymentRepository->delete($scheduledPayment);
    }

    public function processScheduledPayments()
    {
        $today = Carbon::today();
        $duePayments = $this->scheduledPaymentRepository->getDuePayments($today);

        Log::info("Processing {$duePayments->count()} scheduled payments");

        foreach ($duePayments as $payment) {
            $this->processPayment($payment);
        }
    }

    protected function processPayment(ScheduledPayment $payment)
    {
        try {
            $user = $payment->user;
            $result = null;

            switch ($payment->service_type) {
                case 'airtime':
                    $result = $this->airtimeService->purchaseAirtime($user, [
                        'provider_id' => $payment->provider_id,
                        'phone_number' => $payment->recipient,
                        'amount' => $payment->amount,
                        'save_beneficiary' => false
                    ]);
                    break;

                case 'data':
                    $result = $this->dataService->purchaseData($user, [
                        'provider_id' => $payment->provider_id,
                        'phone_number' => $payment->recipient,
                        'package_id' => $payment->metadata['package_id'],
                        'save_beneficiary' => false
                    ]);
                    break;

                case 'cable':
                    $result = $this->cableService->subscribeCable($user, [
                        'provider_id' => $payment->provider_id,
                        'smart_card_number' => $payment->recipient,
                        'package_id' => $payment->metadata['package_id'],
                        'save_beneficiary' => false
                    ]);
                    break;

                case 'electricity':
                    $result = $this->electricityService->payElectricity($user, [
                        'provider_id' => $payment->provider_id,
                        'meter_number' => $payment->recipient,
                        'amount' => $payment->amount,
                        'meter_type' => $payment->metadata['meter_type'],
                        'save_beneficiary' => false
                    ]);
                    break;
            }

            $payment->last_payment_status = 'success';
            $payment->last_payment_date = now();
            $payment->last_transaction_id = $result->id;

            // Update next payment date based on frequency
            $this->updateNextPaymentDate($payment);

            // Dispatch event
            event(new ScheduledPaymentProcessed($payment));

        } catch (\Exception $e) {
            Log::error("Scheduled payment failed: " . $e->getMessage(), [
                'payment_id' => $payment->id,
                'error' => $e->getMessage()
            ]);

            $payment->last_payment_status = 'failed';
            $payment->last_payment_date = now();
            $payment->failure_reason = $e->getMessage();
            $payment->consecutive_failures += 1;

            // If too many failures, pause the payment
            if ($payment->consecutive_failures >= 3) {
                $payment->status = 'paused';
            } else {
                // Still try to update next payment date
                $this->updateNextPaymentDate($payment);
            }

            // Dispatch event
            event(new ScheduledPaymentFailed($payment));
        }

        $payment->save();
    }

    protected function updateNextPaymentDate(ScheduledPayment $payment): void
    {
        $lastDate = $payment->next_payment_date ? Carbon::parse($payment->next_payment_date) : Carbon::today();

        switch ($payment->frequency) {
            case 'daily':
                $nextDate = $lastDate->addDay();
                break;

            case 'weekly':
                $nextDate = $lastDate->addWeek();
                break;

            case 'biweekly':
                $nextDate = $lastDate->addWeeks(2);
                break;

            case 'monthly':
                $nextDate = $lastDate->addMonth();
                break;

            case 'quarterly':
                $nextDate = $lastDate->addMonths(3);
                break;

            default:
                // For one-time payments, set status to completed
                $payment->status = 'completed';
                return;
        }

        $payment->next_payment_date = $nextDate;

        // Reset consecutive failures if payment was successful
        if ($payment->last_payment_status === 'success') {
            $payment->consecutive_failures = 0;
        }
    }

    protected function validateScheduleData(array $data): void
    {
        $validFrequencies = ['one_time', 'daily', 'weekly', 'biweekly', 'monthly', 'quarterly'];

        if (isset($data['frequency']) && !in_array($data['frequency'], $validFrequencies)) {
            throw new \InvalidArgumentException('Invalid frequency specified');
        }

        if (isset($data['start_date'])) {
            $startDate = Carbon::parse($data['start_date']);

            if ($startDate->isPast()) {
                throw new \InvalidArgumentException('Start date cannot be in the past');
            }
        }
    }

    protected function recalculateNextPaymentDate(ScheduledPayment $payment): void
    {
        // Reset next payment date to start date
        $payment->next_payment_date = $payment->start_date;
        $payment->save();
    }
}
