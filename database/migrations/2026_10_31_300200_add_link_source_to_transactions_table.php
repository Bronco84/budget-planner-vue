<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records how a transaction was linked to its recurring template: manual,
 * rule, entity (Plaid entity id), description or analysis. Rule re-evaluation
 * only unlinks 'rule' links. Existing links are left null (unknown), which is
 * treated as a non-rule link and never auto-unlinked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('link_source', 20)->nullable()->after('recurring_transaction_template_id');
            $table->index('link_source');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['link_source']);
            $table->dropColumn('link_source');
        });
    }
};
