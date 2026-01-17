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
        Schema::create('plaid_holdings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plaid_account_id')->constrained('plaid_accounts')->onDelete('cascade');
            $table->foreignId('plaid_security_id')->constrained('plaid_securities')->onDelete('cascade');
            $table->string('plaid_account_identifier'); // The Plaid account_id string
            $table->decimal('quantity', 20, 8); // Number of shares/units (high precision for fractional shares)
            $table->bigInteger('cost_basis_cents')->nullable();
            $table->bigInteger('institution_price_cents')->nullable();
            $table->date('institution_price_as_of')->nullable();
            $table->bigInteger('institution_value_cents')->nullable();
            $table->string('iso_currency_code')->nullable();
            $table->string('unofficial_currency_code')->nullable();
            $table->json('original_data')->nullable();
            $table->timestamps();

            // Composite index for efficient lookups
            $table->index(['plaid_account_id', 'plaid_security_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_holdings');
    }
};
