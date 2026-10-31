<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goals are funded in priority order (lowest first) once a plan's debts are
     * paid off. Existing goals default to 0 and keep their creation order (id).
     */
    public function up(): void
    {
        Schema::table('payoff_plan_goals', function (Blueprint $table) {
            $table->unsignedInteger('priority')->default(0)->after('goal_type');
        });
    }

    public function down(): void
    {
        Schema::table('payoff_plan_goals', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
