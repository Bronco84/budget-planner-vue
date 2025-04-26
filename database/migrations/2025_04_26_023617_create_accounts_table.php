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
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('type')->default('checking'); // checking, savings, credit, investment, other
            $table->bigInteger('current_balance_cents')->default(0);
            $table->timestamp('balance_updated_at')->nullable();
            $table->string('account_number')->nullable();
            $table->string('institution')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_in_budget')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
