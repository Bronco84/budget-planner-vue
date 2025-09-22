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
        Schema::create('bank_feed_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_feed_id')->constrained()->onDelete('cascade');
            $table->string('source_transaction_id')->index(); // Unique ID from external system
            $table->json('raw_data'); // Complete original data from source
            $table->decimal('amount', 15, 2); // Original amount as received
            $table->date('date');
            $table->datetime('datetime')->nullable(); // Full timestamp if available
            $table->string('description');
            $table->string('category')->nullable();
            $table->string('merchant_name')->nullable();
            $table->enum('status', ['pending', 'cleared', 'cancelled'])->default('cleared');
            $table->boolean('pending')->default(false);
            $table->string('pending_transaction_id')->nullable(); // For tracking pending->cleared transitions
            $table->string('currency_code', 3)->default('USD');
            $table->json('metadata')->nullable(); // Additional source-specific data
            $table->timestamp('processed_at')->nullable(); // When this was converted to a Transaction
            $table->timestamps();
            
            // Indexes
            $table->index(['bank_feed_id', 'date']);
            $table->index(['source_transaction_id', 'bank_feed_id']);
            $table->index(['status', 'processed_at']);
            $table->index('date');
            
            // Unique constraint: one transaction per external ID per feed
            $table->unique(['bank_feed_id', 'source_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_feed_transactions');
    }
};