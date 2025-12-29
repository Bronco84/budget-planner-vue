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
        Schema::create('scenario_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->enum('adjustment_type', [
                'one_time_expense',
                'recurring_expense',
                'debt_paydown',
                'savings_contribution',
                'modify_existing'
            ]);
            $table->bigInteger('amount_in_cents');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency')->nullable(); // daily, weekly, biweekly, monthly, quarterly, yearly
            $table->integer('day_of_week')->nullable(); // 0-6 for weekly/biweekly
            $table->integer('day_of_month')->nullable(); // 1-31 for monthly/quarterly/yearly
            $table->text('description')->nullable();
            $table->foreignId('target_recurring_template_id')->nullable()->constrained('recurring_transaction_templates')->onDelete('set null');
            $table->timestamps();

            // Indexes for performance
            $table->index('scenario_id');
            $table->index('account_id');
            $table->index('adjustment_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scenario_adjustments');
    }
};
