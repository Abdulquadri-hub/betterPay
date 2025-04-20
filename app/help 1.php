
<?php



/* app/Http/Controllers/Api/V1/WalletController.php */

<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\FundWalletRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\TransactionResource;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletController extends Controller
{
    protected $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    public function show(Request $request): JsonResponse
    {
        $wallet = $this->walletService->getWalletByUser($request->user());

        return response()->json([
            'data' => new WalletResource($wallet)
        ]);
    }

    public function history(Request $request): AnonymousResourceCollection
    {
        $history = $this->walletService->getWalletHistory($request->user());

        return TransactionResource::collection($history);
    }

    public function fund(FundWalletRequest $request): JsonResponse
    {
        $transaction = $this->walletService->fundWallet(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'data' => [
                'reference' => $transaction->reference,
                'authorization_url' => $transaction->authorization_url
            ]
        ]);
    }

    public function verify(Request $request, string $reference): JsonResponse
    {
        $transaction = $this->walletService->verifyWalletFunding(
            $request->user(),
            $reference
        );

        return response()->json([
            'data' => new TransactionResource($transaction)
        ]);
    }

    public function paystackWebhook(Request $request): JsonResponse
    {
        $this->walletService->handlePaystackWebhook($request->all());

        return response()->json(['status' => 'success']);
    }
}
?>

/* app/Http/Requests/Wallet/FundWalletRequest.php */
<?php
namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class FundWalletRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:100'],
            'payment_method' => ['required', 'string', 'in:paystack,flutterwave,bank_transfer'],
            'metadata' => ['sometimes', 'array']
        ];
    }
}
?>

/* database/migrations/2023_01_01_000001_create_wallets_table.php */
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('currency')->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
?>

/* database/migrations/2023_01_01_000002_create_transactions_table.php */
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('type', [
                'wallet_funding',
                'airtime_purchase',
                'data_purchase',
                'electricity_payment',
                'cable_subscription'
            ]);
            $table->decimal('amount', 12, 2);
            $table->string('reference')->unique();
            $table->string('gateway_reference')->nullable();
            $table->string('provider')->nullable();
            $table->string('recipient')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('metadata')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('authorization_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
?>
