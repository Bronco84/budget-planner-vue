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
        Schema::create('plaid_securities', function (Blueprint $table) {
            $table->id();
            $table->string('plaid_security_id')->unique();
            $table->string('ticker_symbol')->nullable()->index();
            $table->string('name')->nullable();
            $table->string('type')->nullable(); // e.g., 'equity', 'etf', 'mutual fund', 'cash', 'derivative'
            $table->string('isin')->nullable();
            $table->string('cusip')->nullable();
            $table->string('sedol')->nullable();
            $table->bigInteger('close_price_cents')->nullable();
            $table->date('close_price_as_of')->nullable();
            $table->string('iso_currency_code')->nullable();
            $table->string('unofficial_currency_code')->nullable();
            $table->boolean('is_cash_equivalent')->default(false);
            $table->json('original_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_securities');
    }
};
