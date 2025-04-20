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
        Schema::create('scheduled_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('beneficiary_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('service_type', ['airtime', 'data', 'electricity', 'cable']);
            $table->foreignId('provider_id')->constrained();
            $table->string('recipient'); // Phone number, meter number, card number, etc.
            $table->decimal('amount', 12, 2)->nullable();
            $table->foreignId('package_id')->nullable()->constrained('service_packages')->onDelete('set null');
            $table->enum('frequency', ['one_time', 'daily', 'weekly', 'monthly']);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->time('execution_time')->default('08:00:00');
            $table->date('last_execution_date')->nullable();
            $table->date('next_execution_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_payments');
    }
};
