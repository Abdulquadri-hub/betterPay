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


