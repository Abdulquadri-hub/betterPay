<?php

/* app/Http/Middleware/VerifyTransaction.php */
namespace App\Http\Middleware;

use App\Models\Transaction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTransaction
{
    /**
     * Handle an incoming request.
     * This middleware verifies that a transaction belongs to the authenticated user
     * and has the correct status before allowing access.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $requiredStatus = null): Response
    {
        $transactionId = $request->route('id') ?? $request->input('transaction_id');

        if (!$transactionId) {
            return response()->json([
                'message' => 'Transaction ID is required'
            ], 400);
        }

        $transaction = Transaction::findOrFail($transactionId);

        // Verify ownership
        if ($transaction->user_id !== auth()->id()) {
            return response()->json([
                'message' => 'You do not have permission to access this transaction'
            ], 403);
        }

        // Verify status if required
        if ($requiredStatus && $transaction->status !== $requiredStatus) {
            return response()->json([
                'message' => "This transaction is not in '$requiredStatus' status"
            ], 422);
        }

        // Add transaction to request for controller access
        $request->merge(['transaction' => $transaction]);

        return $next($request);
    }
}

/* app/Http/Middleware/EnsureSufficientBalance.php */
namespace App\Http\Middleware;

use App\Exceptions\InsufficientBalanceException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSufficientBalance
{
    /**
     * Handle an incoming request.
     * This middleware checks if the user has sufficient wallet balance
     * before allowing the transaction to proceed.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $amount = $request->input('amount');
        $user = $request->user();
        $wallet = $user->wallet;

        if ($wallet->balance < $amount) {
            throw new InsufficientBalanceException('Insufficient wallet balance');
        }

        return $next($request);
    }
}

/* app/Http/Middleware/ValidateServiceProvider.php */
namespace App\Http\Middleware;

use App\Models\Provider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateServiceProvider
{
    /**
     * Handle an incoming request.
     * This middleware validates that the service provider exists
     * and is active before allowing the transaction.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $serviceType): Response
    {
        $providerId = $request->input('provider_id');

        $provider = Provider::where('id', $providerId)
            ->where('type', $serviceType)
            ->where('is_active', true)
            ->first();

        if (!$provider) {
            return response()->json([
                'message' => 'Invalid or inactive service provider'
            ], 422);
        }

        // Add provider to request for controller access
        $request->merge(['provider' => $provider]);

        return $next($request);
    }
}

/* app/Http/Middleware/ApiKeyAuth.php */
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    /**
     * Handle an incoming request.
     * This middleware validates the API key in header for webhook endpoints.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-KEY');

        if (!$apiKey || $apiKey !== config('services.api_key')) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 401);
        }

        return $next($request);
    }
}

/* app/Services/TransactionService.php */
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
        return $this->transactionRepository->getUserTransactionsPaginated($user, $filters, $perPage);
    }

    public function findUserTransaction(User $user, int $id): ?Transaction
    {
        return $this->transactionRepository->findUserTransaction($user, $id);
    }

    public function getRecentTransactions(User $user, int $limit = 5): Collection
    {
        return $this->transactionRepository->getRecentTransactions($user, $limit);
    }

    public function getTransactionStats(User $user): array
    {
        $lastMonth = Carbon::now()->subMonth();

        $totalSpent = $this->transactionRepository->getUserTotalSpent($user);
        $lastMonthSpent = $this->transactionRepository->getUserSpentSince($user, $lastMonth);
        $transactionsByType = $this->transactionRepository->getTransactionCountByType($user);

        return [
            'total_spent' => $totalSpent,
            'last_month_spent' => $lastMonthSpent,
            'transactions_by_type' => $transactionsByType
        ];
    }

    public function createTransaction(TransactionDTO $dto): Transaction
    {
        $transaction = $this->transactionRepository->create($dto->toArray());

        // Dispatch event
        event(new TransactionInitiated($transaction));

        return $transaction;
    }

    public function completeTransaction(Transaction $transaction, array $providerResponse = null): Transaction
    {
        $transaction->status = 'completed';
        $transaction->completed_at = now();

        if ($providerResponse) {
            $transaction->provider_response = $providerResponse;
        }

        $transaction->save();

        // Dispatch event
        event(new TransactionCompleted($transaction));

        return $transaction;
    }

    public function failTransaction(Transaction $transaction, string $reason = null, array $providerResponse = null): Transaction
    {
        $transaction->status = 'failed';
        $transaction->provider_response = $providerResponse;

        if ($reason) {
            $transaction->metadata = array_merge($transaction->metadata ?? [], ['failure_reason' => $reason]);
        }

        $transaction->save();

        // Dispatch event
        event(new TransactionFailed($transaction));

        return $transaction;
    }
}



/* app/Exceptions/Handler.php */
namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Custom JSON response for API exceptions
        $this->renderable(function (Throwable $e, $request) {
            if ($request->is('api/*')) {
                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'message' => 'Unauthenticated',
                    ], 401);
                }

                if ($e instanceof ValidationException) {
                    return response()->json([
                        'message' => 'The given data was invalid',
                        'errors' => $e->validator->errors()->getMessages(),
                    ], 422);
                }

                if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                    return response()->json([
                        'message' => 'Resource not found',
                    ], 404);
                }

                if ($e instanceof InsufficientBalanceException) {
                    return response()->json([
                        'message' => $e->getMessage(),
                    ], 422);
                }

                if ($e instanceof VTUApiException || $e instanceof PaymentGatewayException) {
                    return response()->json([
                        'message' => $e->getMessage(),
                    ], 422);
                }

                // Log unexpected exceptions
                Log::error('Unexpected exception', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);

                // Return generic error message in production
                if (!config('app.debug')) {
                    return response()->json([
                        'message' => 'Server error. Please try again later.',
                    ], 500);
                }
            }

            return null;
        });
    }
}

/* app/Exceptions/InsufficientBalanceException.php */
namespace App\Exceptions;

use Exception;

class InsufficientBalanceException extends Exception
{
    //
}

/* app/Exceptions/VTUApiException.php */
namespace App\Exceptions;

use Exception;

class VTUApiException extends Exception
{
    //
}

/* app/Exceptions/PaymentGatewayException.php */
namespace App\Exceptions;

use Exception;

class PaymentGatewayException extends Exception
{
    //
}

/* app/Events/TransactionInitiated.php */
namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionInitiated
{
    use Dispatchable, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
}

/* app/Events/TransactionCompleted.php */
namespace App\Events;

use App\Models\Transaction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TransactionCompleted
{
    use Dispatchable, SerializesModels;

    public $transaction;

    public function __construct(Transaction $transaction)
    {
        $this->transaction = $transaction;
    }
}

/* app/Listeners/SendTransactionNotification.php */
namespace App\Listeners;

use App\Events\TransactionCompleted;
use App\Events\TransactionFailed;
use App\Notifications\TransactionCompletedNotification;
use App\Notifications\TransactionFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendTransactionNotification implements ShouldQueue
{
    /**
     * Handle transaction completed event.
     */
    public function handleCompleted(TransactionCompleted $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        // Send notification
        $user->notify(new TransactionCompletedNotification($transaction));
    }

    /**
     * Handle transaction failed event.
     */
    public function handleFailed(TransactionFailed $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        // Send notification
        $user->notify(new TransactionFailedNotification($transaction));
    }
}
