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
        Schema::create('payoff_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('strategy', ['snowball', 'avalanche', 'custom'])->default('avalanche');
            $table->integer('monthly_extra_payment_cents')->default(0); // Extra amount available for debt payoff
            $table->boolean('is_active')->default(true);
            $table->date('start_date');
            $table->timestamps();
        });

        Schema::create('payoff_plan_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payoff_plan_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->integer('starting_balance_cents');
            $table->decimal('interest_rate', 5, 2)->default(0); // Annual interest rate percentage
            $table->integer('minimum_payment_cents')->default(0);
            $table->integer('priority')->default(0); // For custom strategy
            $table->timestamps();
        });

        Schema::create('payoff_plan_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payoff_plan_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "Vacation Fund", "Emergency Fund", "Retirement"
            $table->text('description')->nullable();
            $table->integer('target_amount_cents');
            $table->integer('monthly_contribution_cents')->default(0);
            $table->date('target_date')->nullable();
            $table->enum('goal_type', ['savings', 'investment', 'purchase', 'other'])->default('savings');
            $table->timestamps();
        });

        Schema::create('payoff_plan_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payoff_plan_id')->constrained()->onDelete('cascade');
            $table->date('snapshot_date');
            $table->json('debt_balances'); // Store balance of each debt at this point
            $table->json('goal_progress'); // Store progress of each goal
            $table->integer('total_paid_cents')->default(0);
            $table->timestamps();

            $table->unique(['payoff_plan_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payoff_plan_snapshots');
        Schema::dropIfExists('payoff_plan_goals');
        Schema::dropIfExists('payoff_plan_debts');
        Schema::dropIfExists('payoff_plans');
    }
};
