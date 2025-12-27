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
            // Link to a credit card account for autopay override
            if (!Schema::hasColumn('recurring_transaction_templates', 'linked_credit_card_account_id')) {
                $table->unsignedBigInteger('linked_credit_card_account_id')->nullable()->after('account_id');
            }
            
            $table->foreign('linked_credit_card_account_id', 'rtt_linked_cc_account_id_fk')
                ->references('id')
                ->on('accounts')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_templates', function (Blueprint $table) {
            $table->dropForeign('rtt_linked_cc_account_id_fk');
            $table->dropColumn('linked_credit_card_account_id');
        });
    }
};
