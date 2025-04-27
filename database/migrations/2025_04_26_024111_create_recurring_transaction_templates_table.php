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
        Schema::create('recurring_transaction_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('category')->nullable();
            $table->integer('amount_in_cents');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency')->default('monthly'); // daily, weekly, biweekly, monthly, quarterly, yearly
            $table->integer('day_of_month')->nullable();
            $table->integer('day_of_week')->nullable(); // 0 = Sunday, 6 = Saturday
            $table->integer('week_of_month')->nullable(); // 1-5, 5 = last week
            $table->json('custom_schedule')->nullable();
            $table->boolean('auto_generate')->default(true);
            $table->boolean('is_dynamic_amount')->default(false);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->decimal('average_amount', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_transaction_templates');
    }
};
