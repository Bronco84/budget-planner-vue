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
        // Add foreign key constraints to files table
        Schema::table('files', function (Blueprint $table) {
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('cascade');
        });

        // Add foreign key constraints to file_attachments table
        Schema::table('file_attachments', function (Blueprint $table) {
            $table->foreign('file_id')->references('id')->on('files')->onDelete('cascade');
            $table->foreign('attached_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop foreign key constraints from file_attachments table
        Schema::table('file_attachments', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
            $table->dropForeign(['attached_by']);
        });

        // Drop foreign key constraints from files table
        Schema::table('files', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by']);
        });
    }
}; 