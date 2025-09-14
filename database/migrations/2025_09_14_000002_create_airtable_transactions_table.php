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
        Schema::create('airtable_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            
            // Airtable specific identifiers
            $table->string('airtable_record_id')->unique();
            $table->string('airtable_account_record_id'); // Links to airtable_accounts
            
            // Transaction core data
            $table->decimal('amount', 15, 2); // Higher precision for financial data
            $table->date('date');
            $table->datetime('datetime')->nullable();
            $table->string('description');
            $table->string('category')->nullable();
            
            // Transaction details
            $table->boolean('pending')->default(false);
            $table->string('transaction_type')->nullable(); // debit, credit, etc.
            $table->string('payment_method')->nullable(); // card, ach, etc.
            $table->string('merchant_name')->nullable();
            $table->string('merchant_category')->nullable();
            
            // External source tracking
            $table->string('external_transaction_id')->nullable(); // From fintable
            $table->string('external_source')->default('fintable'); // Source system
            $table->json('fintable_metadata')->nullable(); // Raw data from fintable
            
            // Enhanced categorization (can be populated by fintable)
            $table->string('primary_category')->nullable();
            $table->string('detailed_category')->nullable();
            $table->json('category_metadata')->nullable(); // Additional category info
            
            // Location and merchant details
            $table->json('location')->nullable(); // Store address, coordinates, etc.
            $table->string('merchant_logo_url')->nullable();
            $table->string('merchant_website')->nullable();
            
            // Currency and internationalization
            $table->string('iso_currency_code', 3)->default('USD');
            $table->string('unofficial_currency_code')->nullable();
            
            // Transaction relationships
            $table->string('pending_transaction_id')->nullable(); // For handling pending→settled
            $table->string('transfer_account_id')->nullable(); // For internal transfers
            
            // Sync tracking
            $table->timestamp('last_sync_at')->nullable();
            $table->json('airtable_metadata')->nullable(); // Raw Airtable record data
            
            $table->timestamps();
            
            // Indexes for performance and common queries
            $table->index('airtable_record_id');
            $table->index('airtable_account_record_id');
            $table->index('external_transaction_id');
            $table->index('date');
            $table->index('merchant_name');
            $table->index('category');
            $table->index('pending');
            $table->index('transaction_type');
            $table->index(['account_id', 'date']); // Composite index for account transactions by date
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtable_transactions');
    }
};
