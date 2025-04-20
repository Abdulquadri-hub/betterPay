
<?php

// Add the following to the $routeMiddleware array
protected $routeMiddleware = [
    // ... other middleware
    'api.throttle' => \App\Http\Middleware\CustomThrottleRequests::class,
    'transaction.verify' => \App\Http\Middleware\VerifyTransactionBelongsToUser::class,
];

/* app/Providers/AppServiceProvider.php - For repository bindings */
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Model;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register Repositories
        $this->app->bind(\App\Repositories\UserRepository::class, function ($app) {
            return new \App\Repositories\UserRepository($app->make(\App\Models\User::class));
        });

        $this->app->bind(\App\Repositories\WalletRepository::class, function ($app) {
            return new \App\Repositories\WalletRepository($app->make(\App\Models\Wallet::class));
        });

        $this->app->bind(\App\Repositories\TransactionRepository::class, function ($app) {
            return new \App\Repositories\TransactionRepository($app->make(\App\Models\Transaction::class));
        });

        $this->app->bind(\App\Repositories\ProviderRepository::class, function ($app) {
            return new \App\Repositories\ProviderRepository($app->make(\App\Models\Provider::class));
        });

        $this->app->bind(\App\Repositories\BeneficiaryRepository::class, function ($app) {
            return new \App\Repositories\BeneficiaryRepository($app->make(\App\Models\Beneficiary::class));
        });

        $this->app->bind(\App\Repositories\ScheduledPaymentRepository::class, function ($app) {
            return new \App\Repositories\ScheduledPaymentRepository($app->make(\App\Models\ScheduledPayment::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Enforce strict mode for better data integrity
        Model::shouldBeStrict(!$this->app->isProduction());

        // Set global query scope for soft deletes if needed
        // \App\Models\User::addGlobalScope('active', function ($query) {
        //     $query->where('is_active', true);
        // });
    }
}

/* app/Providers/EventServiceProvider.php - For event listeners */
<?php

namespace App\Providers;

use App\Events\TransactionCompleted;
use App\Events\TransactionFailed;
use App\Events\TransactionInitiated;
use App\Events\WalletFunded;
use App\Listeners\LogTransactionHistory;
use App\Listeners\SendTransactionNotification;
use App\Listeners\SendWalletFundingNotification;
use App\Listeners\UpdateWalletBalance;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        WalletFunded::class => [
            UpdateWalletBalance::class,
            SendWalletFundingNotification::class,
        ],
        TransactionInitiated::class => [
            LogTransactionHistory::class,
        ],
        TransactionCompleted::class => [
            SendTransactionNotification::class,
        ],
        TransactionFailed::class => [
            SendTransactionNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}


/* app/Http/Middleware/CustomThrottleRequests.php */
<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Http\Request;
use Closure;

class CustomThrottleRequests extends ThrottleRequests
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  int|string  $maxAttempts
     * @param  float|int  $decayMinutes
     * @param  string  $prefix
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // Add custom rules for specific endpoints
        if ($request->is('api/v1/auth/login')) {
            $maxAttempts = 5;
            $decayMinutes = 15;
        } elseif ($request->is('api/v1/wallet/fund')) {
            $maxAttempts = 10;
            $decayMinutes = 5;
        } elseif ($request->is('api/v1/airtime/purchase') || $request->is('api/v1/data/purchase')) {
            $maxAttempts = 20;
            $decayMinutes = 2;
        }

        // Generate a custom key that includes the IP and user ID if authenticated
        $key = $prefix . $this->resolveRequestSignature($request);
        if ($request->user()) {
            $key .= ':' . $request->user()->id;
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $key);
    }
}

