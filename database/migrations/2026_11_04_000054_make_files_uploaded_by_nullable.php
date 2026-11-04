<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A File whose S3 object could not be removed while its uploader's account was
 * deleted is detached from the user (uploaded_by = null) instead of being
 * cascaded away with them, so files:cleanup-orphans can retry the S3 delete.
 * The foreign key keeps its cascade for every other file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Detached files have no uploader to restore and no attachments; their S3
        // objects stay behind, as they did before this migration.
        DB::table('files')->whereNull('uploaded_by')->delete();

        Schema::table('files', function (Blueprint $table) {
            $table->unsignedBigInteger('uploaded_by')->nullable(false)->change();
        });
    }
};
