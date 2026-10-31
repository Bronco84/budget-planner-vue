<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File de-duplication is per uploader: identical content uploaded by two users
 * gets one File record each (so neither sees the other's filename), while the
 * S3 object, keyed by content hash, is still shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique(['hash']);
            $table->unique(['hash', 'uploaded_by']);
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->dropUnique(['hash', 'uploaded_by']);
            $table->unique('hash');
        });
    }
};
