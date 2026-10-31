<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bimonthly (twice a month) adjustments store their first day here and the
     * second day in day_of_month, matching recurring transaction templates.
     */
    public function up(): void
    {
        Schema::table('scenario_adjustments', function (Blueprint $table) {
            $table->integer('first_day_of_month')->nullable()->after('day_of_month');
        });
    }

    public function down(): void
    {
        Schema::table('scenario_adjustments', function (Blueprint $table) {
            $table->dropColumn('first_day_of_month');
        });
    }
};
