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
        Schema::create('plaid_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->foreignId('account_id')->constrained()->onDelete('cascade');
            $table->string('plaid_account_id');
            $table->string('plaid_item_id');
            $table->string('institution_name')->nullable();
            $table->bigInteger('current_balance_cents')->default(0);
            $table->bigInteger('available_balance_cents')->nullable();
            $table->timestamp('balance_updated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->string('access_token')->nullable();
            $table->timestamps();
            
            $table->unique(['plaid_account_id', 'plaid_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_accounts');
    }
};
