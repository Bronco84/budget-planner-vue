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
            if (!Schema::hasColumn('transactions', 'recurring_transaction_template_id')) {
                $table->foreignId('recurring_transaction_template_id')
                      ->nullable()
                      ->constrained('recurring_transaction_templates')
                      ->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (Schema::hasColumn('transactions', 'recurring_transaction_template_id')) {
                $table->dropForeign(['recurring_transaction_template_id']);
                $table->dropColumn('recurring_transaction_template_id');
            }
        });
    }
};
