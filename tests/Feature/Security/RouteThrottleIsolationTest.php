<?php

use App\Models\Budget;
use App\Models\User;

// Laravel's throttle middleware keys on the user alone unless a prefix is
// given, so un-prefixed limits on different routes share one counter.

test('requests to one throttled route do not use up another routes limit', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 10) as $i) {
        $this->actingAs($user)->post(route('budgets.files.upload', $budget));
    }

    $this->actingAs($user)
        ->post(route('plaid.sync-all', $budget))
        ->assertStatus(302);
});

test('each throttled route still enforces its own limit', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    foreach (range(1, 3) as $i) {
        $this->actingAs($user)->post(route('plaid.sync-all', $budget))->assertStatus(302);
    }

    $this->actingAs($user)
        ->post(route('plaid.sync-all', $budget))
        ->assertStatus(429);
});
