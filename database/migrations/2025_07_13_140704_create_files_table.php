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
        Schema::create('files', function (Blueprint $table) {
            $table->id();
            $table->string('hash', 64)->unique(); // SHA-256 hash of file content (S3 key)
            $table->string('original_name');
            $table->string('mime_type');
            $table->bigInteger('size_bytes');
            $table->string('extension', 10)->nullable();
            $table->unsignedBigInteger('uploaded_by'); // Will add foreign key constraint later
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
