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
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('google_event_id')->unique(); // Google Calendar event ID
            $table->string('ical_uid')->nullable(); // iCalendar UID for cross-platform compatibility
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_date');
            $table->timestamp('end_date')->nullable();
            $table->boolean('all_day')->default(false);
            $table->string('location')->nullable();
            $table->string('color_id')->nullable();
            $table->timestamp('google_updated_at'); // Last update timestamp from Google
            $table->json('metadata')->nullable(); // Store additional Google Calendar data
            $table->timestamps();
            
            $table->index(['user_id', 'start_date']);
            $table->index(['calendar_connection_id', 'google_updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
