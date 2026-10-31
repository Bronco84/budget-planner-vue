<?php

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ContentModerationService;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

function chatOffenses(User $user): array
{
    return Cache::get("chat_offenses_user_{$user->id}", []);
}

test('retrying past the hourly chat limit does not count as offenses or ban the user', function () {
    $user = User::factory()->create();
    $moderation = app(ContentModerationService::class);

    foreach (range(1, 30) as $i) {
        expect($moderation->checkRateLimit($user)['allowed'])->toBeTrue();
    }

    foreach (range(1, 10) as $i) {
        expect($moderation->checkRateLimit($user)['allowed'])->toBeFalse();
    }

    expect(chatOffenses($user))->toBe([])
        ->and($moderation->isUserBanned($user))->toBeFalse();
});

test('the hourly chat limit is a fixed window that resets an hour after the first message', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $moderation = app(ContentModerationService::class);

    expect($moderation->checkRateLimit($user)['allowed'])->toBeTrue();

    $this->travel(50)->minutes();
    foreach (range(1, 29) as $i) {
        expect($moderation->checkRateLimit($user)['allowed'])->toBeTrue();
    }

    $rejected = $moderation->checkRateLimit($user);
    expect($rejected['allowed'])->toBeFalse()
        ->and($rejected['reset_in_seconds'])->toBe(600);

    $this->travel(11)->minutes();
    expect($moderation->checkRateLimit($user)['allowed'])->toBeTrue();
});

test('sending many messages in one conversation is not flagged as rapid conversation creation', function () {
    Prism::fake(array_fill(0, 15, TextResponseFake::make()->withText('Here is your answer.')));
    $user = User::factory()->create();
    $conversation = ChatConversation::create(['user_id' => $user->id, 'title' => 'Mine']);

    foreach (range(1, 15) as $i) {
        $this->actingAs($user)
            ->postJson('/chat/message', ['message' => "Question {$i}", 'conversation_id' => $conversation->id])
            ->assertOk();
    }

    expect(chatOffenses($user))->toBe([])
        ->and(app(ContentModerationService::class)->isUserBanned($user))->toBeFalse();
});

test('creating many new conversations rapidly is still recorded as an offense', function () {
    Prism::fake(array_fill(0, 11, TextResponseFake::make()->withText('Here is your answer.')));
    $user = User::factory()->create();

    foreach (range(1, 11) as $i) {
        $this->actingAs($user)
            ->postJson('/chat/message', ['message' => "Question {$i}"])
            ->assertOk();
    }

    expect(collect(chatOffenses($user))->pluck('type')->all())->toBe(['rapid_conversation_creation']);
});
