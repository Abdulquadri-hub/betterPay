<?php

namespace App\Http\Controllers\Api\v1;

use App\DTOs\TransactionDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\TransactionCollection;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'type',
            'date_from',
            'date_to',
            'amount_min',
            'amount_max',
            'sort_by',
            'sort_dir'
        ]);

        $transactions = $this->transactionService->getUserTransactions($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transactions)
        ]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $transaction = $this->transactionService->getTransaction($request->user(), $id);

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found or not authorized'
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'success' => true,
            'data' => new TransactionResource($transaction)
        ]);
    }


    public function summary(Request $request): JsonResponse
    {
        $summary = $this->transactionService->getTransactionSummary($request->user());

        return response()->json([
            'success' => true,
            'data' => $summary
        ]);
    }


    public function stats(Request $request): JsonResponse
    {
        $stats = $this->transactionService->getTransactionStats($request->user());

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }


    // public function store(Request $request): JsonResponse
    // {
    //     $user = $request->user();

    //     // Create a DTO from the request data
    //     $transactionData = [
    //         $user->id,
    //         $request->type,
    //         $request->amount,
    //         $request->description ?? null,
    //         $request->reference ?? null,
    //         $request->meta ?? []
    //     ];

    //     // Handle transaction creation through the service
    //     // Note: This method would need to be added to the TransactionService
    //     $transaction = $this->transactionService->createTransaction($transactionData);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Transaction created successfully',
    //         'data' => new TransactionResource($transaction)
    //     ], Response::HTTP_CREATED);
    // }


    // public function update(int $id, Request $request): JsonResponse
    // {
    //     $request->validate([
    //         'description' => 'sometimes|string|max:255',
    //         'meta' => 'sometimes|array',
    //     ]);

    //     $user = $request->user();
    //     $transaction = $this->transactionService->getTransaction($user, $id);

    //     if (!$transaction) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Transaction not found or not authorized'
    //         ], Response::HTTP_NOT_FOUND);
    //     }

    //     // Only allow updating certain fields
    //     $data = $request->only(['description', 'meta']);

    //     // This method would need to be added to the TransactionService
    //     $updatedTransaction = $this->transactionService->updateTransaction($transaction, $data);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Transaction updated successfully',
    //         'data' => new TransactionResource($updatedTransaction)
    //     ]);
    // }


    public function byDateRange(Request $request): JsonResponse
    {
        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $filters = [
            'date_from' => $request->from_date,
            'date_to' => $request->to_date,
        ];

        // Additional filters
        if ($request->has('status')) {
            $filters['status'] = $request->status;
        }

        if ($request->has('type')) {
            $filters['type'] = $request->type;
        }

        $transactions = $this->transactionService->getUserTransactions($request->user(), $filters);

        return response()->json([
            'success' => true,
            'data' => $transactions
        ]);
    }
}
