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
        Schema::table('transactions', function (Blueprint $table) {
            // Add new fields for bank feed integration
            $table->foreignId('bank_feed_transaction_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('import_source', ['manual', 'plaid', 'airtable', 'csv', 'ofx'])->default('manual');
            $table->index(['import_source', 'bank_feed_transaction_id']);
            
            // Note: Keep existing plaid_transaction_id and is_plaid_imported for backwards compatibility
            // during migration. These will be removed in a future migration after data migration.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['bank_feed_transaction_id']);
            $table->dropIndex(['import_source', 'bank_feed_transaction_id']);
            $table->dropColumn(['bank_feed_transaction_id', 'import_source']);
        });
    }
};