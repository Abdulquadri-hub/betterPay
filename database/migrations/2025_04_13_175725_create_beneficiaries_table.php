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
        Schema::create('beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('provider_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('identifier'); // Phone number, meter number, card number, etc.
            $table->enum('service_type', ['airtime', 'data', 'electricity', 'cable']);
            $table->json('metadata')->nullable();
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            // Unique constraint to prevent duplicates for a user
            $table->unique(['user_id', 'identifier', 'service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiaries');
    }
};
