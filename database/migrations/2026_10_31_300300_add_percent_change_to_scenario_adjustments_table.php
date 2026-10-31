<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A modify_existing adjustment changes its target template's occurrences
     * either to a new amount (amount_in_cents) or by a percentage (this column).
     */
    public function up(): void
    {
        Schema::table('scenario_adjustments', function (Blueprint $table) {
            $table->decimal('percent_change', 8, 2)->nullable()->after('amount_in_cents');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_adjustments', function (Blueprint $table) {
            $table->dropColumn('percent_change');
        });
    }
};
