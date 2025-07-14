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
        Schema::create('file_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('file_id'); // Will add foreign key constraint later
            $table->morphs('attachable'); // attachable_type and attachable_id
            $table->unsignedBigInteger('attached_by'); // Will add foreign key constraint later
            $table->string('description')->nullable(); // Optional description for the attachment
            $table->timestamps();

            $table->unique(['file_id', 'attachable_type', 'attachable_id']); // Prevent duplicate attachments
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_attachments');
    }
};
