<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Google event ids are unique per calendar, not globally: an event shared by
 * two app users has the same id in both calendars. Replace the global unique
 * index with one per (calendar_connection_id, google_event_id). Loosening a
 * unique constraint cannot fail on existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unique(['calendar_connection_id', 'google_event_id'], 'calendar_events_connection_event_unique');
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropUnique('calendar_events_google_event_id_unique');
        });
    }

    /**
     * Rolling back fails if the same Google event is now stored for more than
     * one connection; remove those duplicates first.
     */
    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->unique('google_event_id');
        });

        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropUnique('calendar_events_connection_event_unique');
        });
    }
};
