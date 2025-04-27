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
        Schema::create('plaid_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('plaid_transaction_id')->unique();
            $table->string('plaid_account_id');
            $table->boolean('pending')->default(false);
            $table->decimal('amount', 10, 2);
            $table->date('date');
            $table->string('name');
            $table->string('merchant_name')->nullable();
            $table->string('payment_channel')->nullable();
            $table->string('primary_category')->nullable();
            $table->string('detailed_category')->nullable();
            $table->string('category_icon_url')->nullable();
            $table->string('logo_url')->nullable();
            $table->string('website')->nullable();
            $table->json('location')->nullable();
            $table->json('payment_meta')->nullable();
            $table->json('original_data')->nullable();
            $table->timestamps();
            
            $table->index('plaid_account_id');
            $table->index('date');
            $table->index('merchant_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_transactions');
    }
};
