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
        Schema::create('plaid_statement_history', function (Blueprint $table) {
            $table->id();

            // Foreign key to plaid_accounts
            $table->foreignId('plaid_account_id')->constrained()->onDelete('cascade');

            // Statement information
            $table->bigInteger('statement_balance_cents');
            $table->date('statement_issue_date')->index();
            $table->date('payment_due_date')->nullable();
            $table->bigInteger('minimum_payment_cents')->nullable();

            // Credit card metrics
            $table->decimal('apr_percentage', 5, 2)->nullable();
            $table->decimal('credit_utilization_percentage', 5, 2)->nullable();

            $table->timestamps();

            // Ensure we don't create duplicate records for the same statement
            $table->unique(['plaid_account_id', 'statement_issue_date'], 'plaid_stmt_hist_account_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_statement_history');
    }
};
