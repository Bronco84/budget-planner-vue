<?php

namespace App\Policies;

use App\Models\CalendarConnection;
use App\Models\User;

class CalendarConnectionPolicy
{
    /**
     * Determine if the user can view the calendar connection.
     */
    public function view(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    /**
     * Determine if the user can update the calendar connection.
     */
    public function update(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }

    /**
     * Determine if the user can delete the calendar connection.
     */
    public function delete(User $user, CalendarConnection $connection): bool
    {
        return $user->id === $connection->user_id;
    }
}
