<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\WalletController;
use App\Http\Controllers\Api\v1\AirtimeController;
use App\Http\Controllers\Api\v1\DataController;
use App\Http\Controllers\Api\v1\ElectricityController;
use App\Http\Controllers\Api\v1\CableController;
use App\Http\Controllers\Api\v1\TransactionController;
use App\Http\Controllers\Api\v1\BeneficiaryController;
use App\Http\Controllers\Api\v1\ScheduledPaymentController;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('verify_email', [AuthController::class, 'verifyEmail']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot_password', [AuthController::class, 'forgotPassword']);
        Route::post('reset_password', [AuthController::class, 'resetPassword']);
    });

    Route::post('webhooks/paystack', [WalletController::class, 'paystackWebhook']);
    Route::post('webhooks/flutterwave', [WalletController::class, 'flutterwaveWebhook']);

    Route::middleware(['auth:sanctum'])->group(function () {

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('user', [AuthController::class, 'user']);
            Route::put('profile', [AuthController::class, 'updateProfile']);
            Route::put('password', [AuthController::class, 'updatePassword']);
        });

        Route::prefix('wallet')->group(function () {
            Route::get('/', [WalletController::class, 'index']);
            Route::get('/history', [WalletController::class, 'history']);
            Route::post('/fund', [WalletController::class, 'fund']);
            Route::post('/fund/card', [WalletController::class, 'fundWithCard']);
            Route::post('/fund/bank', [WalletController::class, 'fundWithBank']);
            Route::post('/verify', [WalletController::class, 'verifyPayment']);
            Route::post('/submit-otp', [WalletController::class, 'submitOtp']);
            Route::post('/submit-pin', [WalletController::class, 'submitPin']);
            Route::post('/submit-birthday', [WalletController::class, 'submitBirthday']);
            Route::get('/banks', [WalletController::class, 'getBanks']);
        });

        // Airtime
        Route::prefix('airtime')->group(function () {
            Route::get('providers', [AirtimeController::class, 'providers']);
            Route::post('purchase', [AirtimeController::class, 'purchase']);
        });

        // Data
        Route::prefix('data')->group(function () {
            Route::get('providers', [DataController::class, 'providers']);
            Route::get('packages/{providerId}', [DataController::class, 'packages']);
            Route::post('purchase', [DataController::class, 'purchase']);
        });

        // Electricity
        Route::prefix('electricity')->group(function () {
            Route::get('providers', [ElectricityController::class, 'providers']);
            Route::post('verify', [ElectricityController::class, 'verify']);
            Route::post('pay', [ElectricityController::class, 'pay']);
        });

        // Cable
        Route::prefix('cable')->group(function () {
            Route::get('providers', [CableController::class, 'providers']);
            Route::get('packages/{providerId}', [CableController::class, 'packages']);
            Route::post('verify', [CableController::class, 'verify']);
            Route::post('subscribe', [CableController::class, 'subscribe']);
        });

        // Transactions
        Route::prefix('transactions')->group(function () {
            Route::get('/', [TransactionController::class, 'index']);
            Route::get('{id}', [TransactionController::class, 'show']);
        });

        // Beneficiaries
        Route::apiResource('beneficiaries', BeneficiaryController::class);

        // Scheduled Payments
        Route::apiResource('scheduled-payments', ScheduledPaymentController::class);
        Route::patch('scheduled-payments/{id}/toggle', [ScheduledPaymentController::class, 'toggle']);
    });
});
