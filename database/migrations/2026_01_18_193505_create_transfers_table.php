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
        // Create the transfers table (source of truth for inter-account transfers)
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->integer('amount_in_cents'); // Always positive - represents amount moving from -> to
            $table->date('date');
            $table->string('description')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('date');
            $table->index(['budget_id', 'date']);
        });

        // Add transfer_id to transactions table
        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('transfer_id')->nullable()->after('recurring_transaction_template_id')
                ->constrained('transfers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['transfer_id']);
            $table->dropColumn('transfer_id');
        });

        Schema::dropIfExists('transfers');
    }
};
