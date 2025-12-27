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
        Schema::table('plaid_accounts', function (Blueprint $table) {
            // Statement balance information
            $table->bigInteger('last_statement_balance_cents')->nullable()->after('balance_updated_at');
            $table->date('last_statement_issue_date')->nullable()->after('last_statement_balance_cents');

            // Payment tracking
            $table->bigInteger('last_payment_amount_cents')->nullable()->after('last_statement_issue_date');
            $table->date('last_payment_date')->nullable()->after('last_payment_amount_cents');
            $table->date('next_payment_due_date')->nullable()->after('last_payment_date');
            $table->bigInteger('minimum_payment_amount_cents')->nullable()->after('next_payment_due_date');

            // Credit card information
            $table->decimal('apr_percentage', 5, 2)->nullable()->after('minimum_payment_amount_cents');
            $table->bigInteger('credit_limit_cents')->nullable()->after('apr_percentage');

            // Liability data tracking
            $table->timestamp('liability_updated_at')->nullable()->after('credit_limit_cents');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'last_statement_balance_cents',
                'last_statement_issue_date',
                'last_payment_amount_cents',
                'last_payment_date',
                'next_payment_due_date',
                'minimum_payment_amount_cents',
                'apr_percentage',
                'credit_limit_cents',
                'liability_updated_at',
            ]);
        });
    }
};
