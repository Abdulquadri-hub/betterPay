<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
