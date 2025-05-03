<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Repositories\TransactionRepository;
use App\Repositories\WalletRepository;
use App\Events\WalletFunded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaystackWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $reference;
    protected $event;
    protected $payload;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(string $reference, string $event, array $payload)
    {
        $this->reference = $reference;
        $this->event = $event;
        $this->payload = $payload;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(TransactionRepository $transactionRepository, WalletRepository $walletRepository)
    {
        if ($this->event !== 'charge.success') {
            Log::info('Paystack webhook event not relevant: ' . $this->event);
            return;
        }

        $transaction = $transactionRepository->findBygateWayReference($this->reference);

        if (!$transaction || $transaction->status !== 'pending') {
            Log::info('Transaction not found or not in pending status: ' . $this->reference);
            return;
        }

        $this->completeWalletFunding($transaction, $walletRepository);

        Log::info('Successfully processed Paystack webhook for transaction: ' . $this->reference);
    }

    protected function completeWalletFunding(Transaction $transaction, WalletRepository $walletRepository): void
    {
        DB::beginTransaction();

        try {

            $transaction->status = 'completed';
            $transaction->completed_at = now();
            $transaction->save();


            $wallet = $walletRepository->findById($transaction->user->wallet->id);
            $wallet->balance += $transaction->amount;
            $wallet->save();


            event(new WalletFunded($transaction));

            DB::commit();

            Log::info('Wallet funding completed for transaction: ' . $transaction->reference);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to complete wallet funding: ' . $e->getMessage());
            throw $e;
        }
    }
}
