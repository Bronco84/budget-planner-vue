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
        Schema::create('airtable_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            
            // Airtable specific identifiers
            $table->string('airtable_record_id')->unique();
            $table->string('institution_name')->nullable();
            $table->string('account_name')->nullable();
            $table->string('account_type')->nullable();
            $table->string('account_subtype')->nullable();
            
            // Balance information (stored in cents)
            $table->bigInteger('current_balance_cents')->default(0);
            $table->bigInteger('available_balance_cents')->nullable();
            $table->timestamp('balance_updated_at')->nullable();
            
            // Account details
            $table->string('account_number_last_4')->nullable();
            $table->string('routing_number')->nullable();
            $table->boolean('is_active')->default(true);
            
            // Fintable/external source metadata
            $table->string('external_account_id')->nullable(); // From fintable
            $table->string('external_source')->default('fintable'); // Source system
            $table->json('fintable_metadata')->nullable(); // Raw data from fintable
            
            // Sync tracking
            $table->timestamp('last_sync_at')->nullable();
            $table->json('airtable_metadata')->nullable(); // Raw Airtable record data
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('airtable_record_id');
            $table->index('external_account_id');
            $table->index('account_type');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('airtable_accounts');
    }
};
