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
        Schema::create('bank_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->enum('source_type', ['plaid', 'airtable', 'csv', 'ofx', 'manual']);
            $table->json('connection_config')->nullable(); // Store credentials, tokens, etc.
            $table->string('source_account_id')->nullable(); // External account identifier
            $table->string('institution_name')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->enum('status', ['active', 'error', 'disconnected', 'pending'])->default('pending');
            $table->text('error_message')->nullable();
            $table->bigInteger('current_balance_cents')->nullable();
            $table->bigInteger('available_balance_cents')->nullable();
            $table->timestamp('balance_updated_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index(['account_id', 'source_type']);
            $table->index(['budget_id', 'status']);
            $table->index('last_sync_at');
            
            // Unique constraint: one connection per account per source type
            $table->unique(['account_id', 'source_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_feeds');
    }
};