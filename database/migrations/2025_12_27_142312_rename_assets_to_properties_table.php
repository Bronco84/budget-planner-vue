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
        Schema::rename('assets', 'properties');
        
        // Rename the foreign key column in accounts table
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('asset_id', 'property_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->renameColumn('property_id', 'asset_id');
        });
        
        Schema::rename('properties', 'assets');
    }
};
