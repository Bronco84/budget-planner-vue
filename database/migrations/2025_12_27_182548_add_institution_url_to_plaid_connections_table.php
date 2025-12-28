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
        Schema::table('plaid_connections', function (Blueprint $table) {
            $table->string('institution_url')->nullable()->after('institution_logo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('plaid_connections', function (Blueprint $table) {
            $table->dropColumn('institution_url');
        });
    }
};
