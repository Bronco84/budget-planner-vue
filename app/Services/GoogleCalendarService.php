<?php

namespace App\Services;

use App\Models\CalendarConnection;
use App\Models\CalendarEvent;
use App\Models\User;
use Carbon\Carbon;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected GoogleClient $client;

    public function __construct()
    {
        $this->client = new GoogleClient();
        $this->client->setClientId(config('services.google.calendar.client_id'));
        $this->client->setClientSecret(config('services.google.calendar.client_secret'));
        $this->client->setRedirectUri(config('services.google.calendar.redirect_uri'));
        $this->client->setScopes([GoogleCalendar::CALENDAR_READONLY]);
        $this->client->setAccessType('offline');
        $this->client->setPrompt('consent');
    }

    /**
     * Get the authorization URL for Google OAuth.
     */
    public function getAuthorizationUrl(User $user): string
    {
        $this->client->setState(json_encode(['user_id' => $user->id]));
        return $this->client->createAuthUrl();
    }

    /**
     * Handle the OAuth callback and create a calendar connection.
     */
    public function handleCallback(string $code, User $user, string $calendarId = 'primary'): CalendarConnection
    {
        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new \Exception('Error fetching access token: ' . $token['error']);
        }

        $this->client->setAccessToken($token);

        // Get calendar info
        $calendar = new GoogleCalendar($this->client);
        $calendarInfo = $calendar->calendars->get($calendarId);

        // Create or update calendar connection
        $connection = CalendarConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'calendar_id' => $calendarId,
            ],
            [
                'provider' => 'google',
                'calendar_name' => $calendarInfo->getSummary(),
                'access_token' => $token['access_token'],
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in']) 
                    ? now()->addSeconds($token['expires_in']) 
                    : null,
                'is_active' => true,
            ]
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
        if (!$connection->isTokenExpired()) {
            return;
        }

        if (!$connection->refresh_token) {
            throw new \Exception('No refresh token available');
        }

        $this->client->setAccessToken([
            'access_token' => $connection->access_token,
            'refresh_token' => $connection->refresh_token,
        ]);

        if ($this->client->isAccessTokenExpired()) {
            $token = $this->client->fetchAccessTokenWithRefreshToken($connection->refresh_token);

            if (isset($token['error'])) {
                throw new \Exception('Error refreshing token: ' . $token['error']);
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
            $events = $calendar->events->listEvents($connection->calendar_id, $optParams);
            $syncedCount = 0;

            foreach ($events->getItems() as $event) {
                $this->syncEvent($connection, $event);
                $syncedCount++;
            }

            $connection->markSynced();

            Log::info('Google Calendar sync completed', [
                'connection_id' => $connection->id,
                'events_synced' => $syncedCount,
            ]);

            return $syncedCount;
        } catch (\Exception $e) {
            Log::error('Google Calendar sync failed', [
                'connection_id' => $connection->id,
                'error' => $e->getMessage(),
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
        $allDay = !empty($start->date);
        $startDate = $allDay ? Carbon::parse($start->date) : Carbon::parse($start->dateTime);
        $endDate = $allDay && $end->date ? Carbon::parse($end->date) : ($end->dateTime ? Carbon::parse($end->dateTime) : null);

        return CalendarEvent::updateOrCreate(
            [
                'google_event_id' => $googleEvent->getId(),
            ],
            [
                'calendar_connection_id' => $connection->id,
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
     * Delete events that were removed from Google Calendar.
     */
    public function deleteRemovedEvents(CalendarConnection $connection): int
    {
        $this->refreshTokenIfNeeded($connection);

        $this->client->setAccessToken($connection->access_token);
        $calendar = new GoogleCalendar($this->client);

        $deletedCount = 0;
        $localEvents = $connection->events;

        foreach ($localEvents as $localEvent) {
            try {
                $calendar->events->get($connection->calendar_id, $localEvent->google_event_id);
            } catch (\Google_Service_Exception $e) {
                if ($e->getCode() === 404) {
                    $localEvent->delete();
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
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

