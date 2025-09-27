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
        Schema::create('plaid_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('plaid_item_id')->unique(); // Plaid's unique item identifier
            $table->string('institution_id')->nullable(); // Plaid's institution identifier
            $table->string('institution_name');
            $table->string('access_token'); // Single access token for entire institution
            $table->string('status')->default('active'); // active, error, disconnected, expired
            $table->text('error_message')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();
            
            // Ensure one connection per item per budget
            $table->unique(['budget_id', 'plaid_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plaid_connections');
    }
};