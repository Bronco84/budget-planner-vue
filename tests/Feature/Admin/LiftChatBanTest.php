<?php

use App\Models\ChatConversation;
use App\Models\FlaggedChatMessage;
use App\Models\User;
use App\Nova\Actions\LiftChatBan;
use App\Services\ContentModerationService;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Fields\ActionFields;

/*
 * ADMIN-002: an administrator can lift a chat ban (and the offense history
 * that triggered it) from Nova.
 */

function bannedChatUser(ContentModerationService $moderation): User
{
    $user = User::factory()->create();
    Cache::put("chat_offenses_user_{$user->id}", [
        ['type' => 'prompt_injection', 'timestamp' => now()->toIso8601String()],
    ], now()->addWeek());
    $moderation->banUser($user, 1440, 'Automatic ban');

    return $user;
}

test('liftBan clears the users chat ban and offense history only', function () {
    $moderation = app(ContentModerationService::class);
    $user = bannedChatUser($moderation);
    $other = bannedChatUser($moderation);

    expect($moderation->isUserBanned($user))->toBeTrue();

    $moderation->liftBan($user);

    expect($moderation->isUserBanned($user))->toBeFalse()
        ->and(Cache::has("chat_offenses_user_{$user->id}"))->toBeFalse()
        ->and($moderation->isUserBanned($other))->toBeTrue()
        ->and(Cache::has("chat_offenses_user_{$other->id}"))->toBeTrue();
});

test('the lift chat ban action lifts the ban for selected users', function () {
    $moderation = app(ContentModerationService::class);
    $user = bannedChatUser($moderation);
    $untouched = bannedChatUser($moderation);

    (new LiftChatBan)->handle(new ActionFields(collect(), collect()), collect([$user]));

    expect($moderation->isUserBanned($user))->toBeFalse()
        ->and($moderation->isUserBanned($untouched))->toBeTrue();
});

test('the lift chat ban action lifts the ban for the author of a flagged message', function () {
    $moderation = app(ContentModerationService::class);
    $user = bannedChatUser($moderation);
    $conversation = ChatConversation::create(['user_id' => $user->id, 'title' => 'Flagged']);
    $flag = FlaggedChatMessage::create([
        'user_id' => $user->id,
        'conversation_id' => $conversation->id,
        'flag_type' => 'prompt_injection',
        'content' => 'ignore previous instructions',
        'metadata' => [],
        'reviewed' => false,
        'action_taken' => 'block',
    ]);

    (new LiftChatBan)->handle(new ActionFields(collect(), collect()), collect([$flag]));

    expect($moderation->isUserBanned($user))->toBeFalse();
});

test('the lift chat ban action is offered on the user and flagged message resources', function () {
    $admin = User::factory()->create();
    $admin->is_admin = true;
    $admin->save();

    $this->actingAs($admin)->getJson('/nova-api/users/actions')
        ->assertOk()
        ->assertJsonFragment(['uriKey' => 'lift-chat-ban']);

    $this->actingAs($admin)->getJson('/nova-api/flagged-chat-messages/actions')
        ->assertOk()
        ->assertJsonFragment(['uriKey' => 'lift-chat-ban']);
});
