<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A pending Plaid transaction the bank drops (a voided hold, a cancelled order) is
 * never reported as removed by /transactions/get. Sync now marks such rows
 * removed_by_bank_at, which hides them from totals while the user decides to delete
 * or keep them; keeping one sets bank_removal_kept_at so sync never marks it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('removed_by_bank_at')->nullable();
            $table->timestamp('bank_removal_kept_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['removed_by_bank_at', 'bank_removal_kept_at']);
        });
    }
};
