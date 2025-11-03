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
        Schema::create('flagged_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->cascadeOnDelete();
            $table->string('flag_type'); // 'prompt_injection', 'harmful_instructions', 'abusive_content', etc.
            $table->text('content'); // The flagged content
            $table->json('metadata')->nullable(); // Additional context (IP, user agent, etc.)
            $table->boolean('reviewed')->default(false);
            $table->string('action_taken')->nullable(); // 'blocked', 'warned', 'logged', 'banned'
            $table->timestamps();

            $table->index(['user_id', 'flag_type']);
            $table->index('reviewed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flagged_chat_messages');
    }
};
