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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('category')->nullable();
            $table->integer('amount_in_cents');
            $table->date('date')->nullable();
            $table->unsignedBigInteger('plaid_transaction_id')->nullable();
            $table->boolean('is_plaid_imported')->default(false);
            $table->boolean('is_reconciled')->default(false);
            $table->unsignedBigInteger('recurring_transaction_template_id')->nullable();
            $table->unsignedBigInteger('pattern_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('date');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
