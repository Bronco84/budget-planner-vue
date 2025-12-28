<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CalendarConnectionController extends Controller
{
    public function __construct(
        protected GoogleCalendarService $googleCalendarService
    ) {}

    /**
     * Show calendar connections settings.
     */
    public function index(): Response
    {
        $connections = auth()->user()->calendarConnections()
            ->with('events')
            ->get()
            ->map(function ($connection) {
                return [
                    'id' => $connection->id,
                    'provider' => $connection->provider,
                    'calendar_name' => $connection->calendar_name,
                    'calendar_id' => $connection->calendar_id,
                    'is_active' => $connection->is_active,
                    'last_synced_at' => $connection->last_synced_at?->diffForHumans(),
                    'events_count' => $connection->events()->count(),
                ];
            });

        return Inertia::render('Calendar/Connections', [
            'connections' => $connections,
        ]);
    }

    /**
     * Redirect to Google OAuth.
     */
    public function connect(): RedirectResponse
    {
        $authUrl = $this->googleCalendarService->getAuthorizationUrl(auth()->user());
        return redirect()->away($authUrl);
    }

    /**
     * Handle Google OAuth callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return redirect()->route('calendar.connections.index')
                ->with('error', 'Calendar connection was cancelled or failed.');
        }

        try {
            $connection = $this->googleCalendarService->handleCallback(
                $request->get('code'),
                auth()->user()
            );

            return redirect()->route('calendar.connections.index')
                ->with('success', "Successfully connected to {$connection->calendar_name}!");
        } catch (\Exception $e) {
            return redirect()->route('calendar.connections.index')
                ->with('error', 'Failed to connect calendar: ' . $e->getMessage());
        }
    }

    /**
     * Sync events from a calendar connection.
     */
    public function sync(CalendarConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        try {
            $count = $this->googleCalendarService->syncEvents($connection);

            return redirect()->back()
                ->with('success', "Synced {$count} events from {$connection->calendar_name}");
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Toggle a calendar connection active status.
     */
    public function toggle(CalendarConnection $connection): RedirectResponse
    {
        $this->authorize('update', $connection);

        $connection->update(['is_active' => !$connection->is_active]);

        $status = $connection->is_active ? 'enabled' : 'disabled';
        return redirect()->back()
            ->with('success', "Calendar connection {$status}");
    }

    /**
     * Delete a calendar connection.
     */
    public function destroy(CalendarConnection $connection): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $connection->delete();

        return redirect()->back()
            ->with('success', 'Calendar connection removed');
    }
}
