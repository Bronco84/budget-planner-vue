<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Support\ExceptionContext;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient;
        $this->client->setClientId(config('services.google.calendar.client_id'));
        $this->client->setClientSecret(config('services.google.calendar.client_secret'));
        $this->client->setRedirectUri(config('services.google.calendar.redirect_uri'));
        $this->client->setScopes([GoogleCalendar::CALENDAR_READONLY]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Get the authorization URL for Google OAuth. The state must be a random
     * nonce the caller stores and verifies on the callback (OAuth CSRF).
     */
    public function getAuthorizationUrl(User $user, string $state): string
    {
        $this->client->setState($state);

        return $this->client->createAuthUrl();
    }

    /**
     * Handle the OAuth callback and create a calendar connection.
     */
    public function handleCallback(string $code, User $user, string $calendarId = 'primary'): CalendarConnection
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \Exception('Error fetching access token: '.$token['error']);
        }

        $this->client->setAccessToken($token);

        // Get calendar info
        $calendar = new GoogleCalendar($this->client);
        $calendarInfo = $calendar->calendars->get($calendarId);

        // Google only returns a refresh token on first consent; on a reconnect
        // keep the stored one rather than overwriting it with null.
        $attributes = [
            'provider' => 'google',
            'calendar_name' => $calendarInfo->getSummary(),
            'access_token' => $token['access_token'],
            'token_expires_at' => isset($token['expires_in'])
                ? now()->addSeconds($token['expires_in'])
                : null,
            'is_active' => true,
        ];
        if (! empty($token['refresh_token'])) {
            $attributes['refresh_token'] = $token['refresh_token'];
        }

        // Create or update calendar connection
        $connection = CalendarConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'calendar_id' => $calendarId,
            ],
            $attributes
        );

        // Perform initial sync
        $this->syncEvents($connection);

        return $connection;
    }

    /**
     * Refresh the access token if expired.
     */
    public function refreshTokenIfNeeded(CalendarConnection $connection): void
    {
        if (! $connection->isTokenExpired()) {
            return;
        }

        if (! $connection->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        $this->client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
        ]);

        if ($this->client->isAccessTokenExpired()) {
            $token = $this->client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (isset($token['error'])) {
                throw new \Exception('Error refreshing token: '.$token['error']);
            }

            $connection->update([
                'access_token' => $token['access_token'],
                'token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds($token['expires_in'])
                    : null,
            ]);
        }
    }

    /**
     * Revoke the Google grant behind a connection that has just been deleted,
     * so it no longer works at Google. Never throws: a failure is logged and
     * must not stop the deletion. A grant still used by another of the user's
     * connections is left alone.
     */
    public function revokeAccess(CalendarConnection $connection): bool
    {
        try {
            $token = $connection->refresh_token ?: $connection->access_token;

            if (! $token) {
                return false;
            }

            $sharedGrant = CalendarConnection::query()
                ->where('user_id', $connection->user_id)
                ->whereKeyNot($connection->getKey())
                ->get()
                ->contains(fn (CalendarConnection $other) => in_array($token, [$other->refresh_token, $other->access_token], true));

            if ($sharedGrant) {
                return false;
            }

            if ($this->client->revokeToken($token)) {
                return true;
            }

            Log::warning('Google Calendar grant revocation was refused', [
                'connection_id' => $connection->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google Calendar grant revocation failed', [
                'connection_id' => $connection->id,
                ...ExceptionContext::withoutArguments($e),
            ]);
        }

        return false;
    }

    /**
     * Sync events from Google Calendar.
     */
    public function syncEvents(CalendarConnection $connection, ?Carbon $startDate = null, ?Carbon $endDate = null): int
    {
        $this->refreshTokenIfNeeded($connection);

        $this->client->setAccessToken($connection->access_token);
        $calendar = new GoogleCalendar($this->client);

        $startDate = $startDate ?? now()->subMonths(1);
        $endDate = $endDate ?? now()->addMonths(6);

        $optParams = [
            'timeMin' => $startDate->toRfc3339String(),
            'timeMax' => $endDate->toRfc3339String(),
            'singleEvents' => true,
            'orderBy' => 'startTime',
        ];

        try {
            $syncedCount = 0;
            $seenEventIds = [];

            // Walk every page: events.list returns at most 250 items per page,
            // and the prune below is only safe once the whole window is known.
            do {
                $events = $calendar->events->listEvents($connection->calendar_id, $optParams);

                foreach ($events->getItems() as $event) {
                    if ($event->getStatus() === 'cancelled') {
                        continue;
                    }

                    $this->syncEvent($connection, $event);
                    $seenEventIds[] = $event->getId();
                    $syncedCount++;
                }

                $optParams['pageToken'] = $events->getNextPageToken();
            } while ($optParams['pageToken']);

            $deletedCount = $this->deleteRemovedEvents($connection, $seenEventIds, $startDate, $endDate);

            $connection->markSynced();

            Log::info('Google Calendar sync completed', [
                'connection_id' => $connection->id,
                'events_synced' => $syncedCount,
                'events_deleted' => $deletedCount,
            ]);

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('Google Calendar sync failed', [
                'connection_id' => $connection->id,
                ...ExceptionContext::withoutArguments($e),
            ]);

            throw $e;
        }
    }

    /**
     * Sync a single event from Google Calendar.
     */
    protected function syncEvent(CalendarConnection $connection, \Google_Service_Calendar_Event $googleEvent): CalendarEvent
    {
        $start = $googleEvent->getStart();
        $end = $googleEvent->getEnd();

        // Handle all-day events
        $allDay = ! empty($start->date);
        $startDate = $allDay ? Carbon::parse($start->date) : Carbon::parse($start->dateTime);
        $endDate = $allDay && $end->date ? Carbon::parse($end->date) : ($end->dateTime ? Carbon::parse($end->dateTime) : null);

        // Google event ids are only unique within a calendar: an event shared
        // by several invitees has the same id in each of their calendars.
        return CalendarEvent::updateOrCreate(
            [
                'calendar_connection_id' => $connection->id,
                'google_event_id' => $googleEvent->getId(),
            ],
            [
                'user_id' => $connection->user_id,
                'ical_uid' => $googleEvent->getICalUID(),
                'title' => $googleEvent->getSummary() ?? '(No title)',
                'description' => $googleEvent->getDescription(),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'all_day' => $allDay,
                'location' => $googleEvent->getLocation(),
                'color_id' => $googleEvent->getColorId(),
                'google_updated_at' => Carbon::parse($googleEvent->getUpdated()),
                'metadata' => [
                    'status' => $googleEvent->getStatus(),
                    'html_link' => $googleEvent->getHtmlLink(),
                    'organizer' => $googleEvent->getOrganizer() ? [
                        'email' => $googleEvent->getOrganizer()->getEmail(),
                        'display_name' => $googleEvent->getOrganizer()->getDisplayName(),
                    ] : null,
                ],
            ]
        );
    }

    /**
     * Delete this connection's events in the synced window that Google no
     * longer returned (deleted, cancelled or moved out of the window). Only
     * call this after a complete listing of that window.
     *
     * @param  array<int, string>  $seenEventIds
     */
    protected function deleteRemovedEvents(CalendarConnection $connection, array $seenEventIds, Carbon $startDate, Carbon $endDate): int
    {
        return CalendarEvent::query()
            ->where('calendar_connection_id', $connection->id)
            ->where('start_date', '>=', $startDate)
            ->where('start_date', '<', $endDate)
            ->whereNotIn('google_event_id', $seenEventIds)
            ->delete();
    }

    /**
     * Get the user's calendar list.
     */
    public function getCalendarList(CalendarConnection $connection): array
    {
        $this->refreshTokenIfNeeded($connection);

        $this->client->setAccessToken($connection->access_token);
        $calendar = new GoogleCalendar($this->client);

        $calendarList = $calendar->calendarList->listCalendarList();
        $calendars = [];

        foreach ($calendarList->getItems() as $calendarListEntry) {
            $calendars[] = [
                'id' => $calendarListEntry->getId(),
                'summary' => $calendarListEntry->getSummary(),
                'description' => $calendarListEntry->getDescription(),
                'primary' => $calendarListEntry->getPrimary(),
                'backgroundColor' => $calendarListEntry->getBackgroundColor(),
            ];
        }

        return $calendars;
    }
}
