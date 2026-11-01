<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Records the session a trusted device last signed in with, so revoking
     * the device can also end that live session. Nullable: existing devices
     * have no recorded session until their next sign-in.
     */
    public function up(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->string('session_id')->nullable()->after('auth_method');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trusted_devices', function (Blueprint $table) {
            $table->dropColumn('session_id');
        });
    }
};
