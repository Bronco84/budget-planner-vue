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
            $table->integer('first_day_of_month')->nullable()->after('day_of_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_transaction_templates', function (Blueprint $table) {
            $table->dropColumn('first_day_of_month');
        });
    }
};
