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
        Schema::table('recurring_transaction_templates', function (Blueprint $table) {
            $table->string('plaid_entity_id')->nullable()->after('linked_credit_card_account_id');
            $table->string('plaid_entity_name')->nullable()->after('plaid_entity_id');
            $table->index('plaid_entity_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_templates', function (Blueprint $table) {
            $table->dropIndex(['plaid_entity_id']);
            $table->dropColumn('plaid_entity_id');
            $table->dropColumn('plaid_entity_name');
        });
    }
};
