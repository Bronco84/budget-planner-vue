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
        Schema::table('plaid_transactions', function (Blueprint $table) {
            // Add columns that are missing from the current schema
            $table->foreignId('account_id')->nullable()->after('id');
            $table->string('transaction_id')->nullable()->after('account_id');
            $table->dateTime('datetime')->nullable()->after('date');
            $table->date('authorized_date')->nullable()->after('datetime');
            $table->dateTime('authorized_datetime')->nullable()->after('authorized_date');
            $table->string('merchant_entity_id')->nullable()->after('merchant_name');
            $table->string('transaction_code')->nullable()->after('payment_channel');
            $table->string('transaction_type')->nullable()->after('transaction_code');
            $table->string('pending_transaction_id')->nullable()->after('pending');
            $table->string('iso_currency_code')->nullable()->after('pending_transaction_id');
            $table->string('unofficial_currency_code')->nullable()->after('iso_currency_code');
            $table->string('check_number')->nullable()->after('unofficial_currency_code');
            
            // Rename primary_category and detailed_category to match the requested schema
            $table->renameColumn('primary_category', 'category');
            $table->renameColumn('detailed_category', 'category_id');
            
            // Add additional JSON columns
            $table->json('counterparties')->nullable()->after('category_id');
            $table->json('personal_finance_category')->nullable()->after('payment_meta');
            $table->string('personal_finance_category_icon_url')->nullable()->after('personal_finance_category');
            $table->json('metadata')->nullable()->after('personal_finance_category_icon_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_transactions', function (Blueprint $table) {
            // Revert column renames
            $table->renameColumn('category', 'primary_category');
            $table->renameColumn('category_id', 'detailed_category');
            
            // Drop added columns
            $table->dropColumn([
                'account_id',
                'transaction_id',
                'datetime',
                'authorized_date',
                'authorized_datetime',
                'merchant_entity_id',
                'transaction_code',
                'transaction_type',
                'pending_transaction_id',
                'iso_currency_code',
                'unofficial_currency_code',
                'check_number',
                'counterparties',
                'personal_finance_category',
                'personal_finance_category_icon_url',
                'metadata'
            ]);
        });
    }
}; 