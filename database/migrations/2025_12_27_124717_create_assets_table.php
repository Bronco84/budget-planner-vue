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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->onDelete('cascade');
            $table->string('name'); // e.g., "Main Home", "2015 Honda Civic"
            $table->enum('type', ['property', 'vehicle', 'other'])->default('other');
            $table->bigInteger('current_value_cents'); // Current estimated value in cents
            $table->timestamp('value_updated_at')->nullable(); // When value was last updated
            
            // Property-specific fields
            $table->text('address')->nullable();
            $table->string('property_type')->nullable(); // single_family, condo, townhouse, etc.
            $table->integer('bedrooms')->nullable();
            $table->integer('bathrooms')->nullable();
            $table->integer('square_feet')->nullable();
            $table->integer('year_built')->nullable();
            
            // Vehicle-specific fields
            $table->string('vehicle_make')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->integer('vehicle_year')->nullable();
            $table->string('vin')->nullable();
            $table->integer('mileage')->nullable();
            
            // API integration fields (for future use)
            $table->string('api_source')->nullable(); // 'zillow', 'redfin', 'kbb', 'manual'
            $table->string('api_id')->nullable(); // External ID from API
            $table->timestamp('api_last_synced_at')->nullable();
            
            // Notes and metadata
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('budget_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
