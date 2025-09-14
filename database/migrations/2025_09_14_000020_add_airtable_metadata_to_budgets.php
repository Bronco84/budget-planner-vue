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
        Schema::table('budgets', function (Blueprint $table) {
            // Optional: Track which Airtable base this budget syncs with
            $table->string('airtable_base_id')->nullable()->after('description');
            $table->timestamp('last_airtable_sync')->nullable()->after('airtable_base_id');
            $table->json('airtable_sync_summary')->nullable()->after('last_airtable_sync');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('budgets', function (Blueprint $table) {
            $table->dropColumn(['airtable_base_id', 'last_airtable_sync', 'airtable_sync_summary']);
        });
    }
};
