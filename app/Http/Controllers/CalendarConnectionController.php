<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CalendarConnectionController extends Controller
{
    /**
     * Session key holding the OAuth state nonce for the in-flight Google consent.
     */
    protected const OAUTH_STATE_KEY = 'calendar_oauth_state';

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
    public function connect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::OAUTH_STATE_KEY, $state);

        $authUrl = $this->googleCalendarService->getAuthorizationUrl(auth()->user(), $state);

        return redirect()->away($authUrl);
    }

    /**
     * Handle Google OAuth callback.
     */
    public function callback(Request $request): RedirectResponse
    {
        // Single use: the nonce is cleared whatever the outcome.
        $expectedState = $request->session()->pull(self::OAUTH_STATE_KEY);

        if ($request->has('error')) {
            return redirect()->route('calendar.connections.index')
                ->with('error', 'Calendar connection was cancelled or failed.');
        }

        $state = $request->query('state');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)) {
            return redirect()->route('calendar.connections.index')
                ->with('error', 'Calendar connection failed: the request could not be verified. Please try again.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('calendar.connections.index')
                ->with('error', 'Calendar connection failed: no authorization code was returned.');
        }

        try {
            $connection = $this->googleCalendarService->handleCallback(
                $code,
                auth()->user()
            );

            return redirect()->route('calendar.connections.index')
                ->with('message', "Successfully connected to {$connection->calendar_name}!");
        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth callback failed', [
                'user_id' => auth()->id(),
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return redirect()->route('calendar.connections.index')
                ->with('error', 'Failed to connect calendar. Please try again.');
        }
    }

    /**
     * Sync events from a calendar connection.
     */
    public function sync(CalendarConnection $calendarConnection): RedirectResponse
    {
        $this->authorize('update', $calendarConnection);

        try {
            $count = $this->googleCalendarService->syncEvents($calendarConnection);

            return redirect()->back()
                ->with('message', "Synced {$count} events from {$calendarConnection->calendar_name}");
        } catch (\Exception $e) {
            Log::error('Google Calendar sync failed', [
                'calendar_connection_id' => $calendarConnection->id,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->with('error', 'Sync failed. Please try again.');
        }
    }

    /**
     * Toggle a calendar connection active status.
     */
    public function toggle(CalendarConnection $calendarConnection): RedirectResponse
    {
        $this->authorize('update', $calendarConnection);

        $calendarConnection->update(['is_active' => ! $calendarConnection->is_active]);

        $status = $calendarConnection->is_active ? 'enabled' : 'disabled';

        return redirect()->back()
            ->with('message', "Calendar connection {$status}");
    }

    /**
     * Delete a calendar connection.
     */
    public function destroy(CalendarConnection $calendarConnection): RedirectResponse
    {
        $this->authorize('delete', $calendarConnection);

        $calendarConnection->delete();

        // Logged and non-blocking: the connection is gone here either way.
        $this->googleCalendarService->revokeAccess($calendarConnection);

        return redirect()->back()
            ->with('message', 'Calendar connection removed');
    }
}
