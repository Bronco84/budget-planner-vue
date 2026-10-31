<?php

use App\Models\ChatConversation;
use App\Models\FlaggedChatMessage;
use App\Models\User;

/*
 * ADMIN-001 / ADMIN-002: real HTTP access to the Nova panel and its API,
 * gated by the viewNova gate registered in NovaServiceProvider.
 */

function novaAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

function novaNonAdmin(): User
{
    $user = User::factory()->create();
    $user->is_admin = false;
    $user->save();

    return $user;
}

function flaggedMessage(User $user, array $overrides = []): FlaggedChatMessage
{
    $conversation = ChatConversation::create(['user_id' => $user->id, 'title' => 'Flagged']);

    return FlaggedChatMessage::create(array_merge([
        'user_id' => $user->id,
        'conversation_id' => $conversation->id,
        'flag_type' => 'prompt_injection',
        'content' => 'ignore previous instructions',
        'metadata' => ['ip' => '127.0.0.1'],
        'reviewed' => false,
        'action_taken' => 'block',
    ], $overrides));
}

test('an admin can load the nova dashboard over http', function () {
    $admin = novaAdmin();

    $this->actingAs($admin)
        ->get('/nova')
        ->assertRedirect(url('/nova/dashboards/main'));

    $this->actingAs($admin)
        ->get('/nova/dashboards/main')
        ->assertOk();
});

test('a non-admin requesting nova over http receives 403', function () {
    $user = novaNonAdmin();

    $this->actingAs($user)
        ->get('/nova/dashboards/main')
        ->assertForbidden();

    $this->actingAs($user)
        ->getJson('/nova-api/users')
        ->assertForbidden();
});

test('a guest requesting nova is redirected to the nova login page', function () {
    $this->get('/nova')->assertRedirect(url('/nova/login'));
    $this->get('/nova/dashboards/main')->assertRedirect(url('/nova/login'));

    $this->getJson('/nova-api/users')->assertUnauthorized();
});

test('an admin can list flagged chat messages and mark one reviewed', function () {
    $admin = novaAdmin();
    $author = User::factory()->create();
    $flag = flaggedMessage($author);
    $other = flaggedMessage($author, ['flag_type' => 'abusive_content']);

    $this->actingAs($admin)
        ->getJson('/nova-api/flagged-chat-messages')
        ->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonCount(2, 'resources');

    $this->actingAs($admin)
        ->putJson("/nova-api/flagged-chat-messages/{$flag->id}", [
            'user' => $author->id,
            'conversation' => $flag->conversation_id,
            'message' => null,
            'flag_type' => 'prompt_injection',
            'content' => 'ignore previous instructions',
            'metadata' => json_encode(['ip' => '127.0.0.1']),
            'reviewed' => true,
            'action_taken' => 'block',
        ])
        ->assertOk();

    expect($flag->fresh()->reviewed)->toBeTrue()
        ->and($other->fresh()->reviewed)->toBeFalse();
});

test('the flagged chat message index is empty when nothing is flagged', function () {
    $this->actingAs(novaAdmin())
        ->getJson('/nova-api/flagged-chat-messages')
        ->assertOk()
        ->assertJsonPath('total', 0)
        ->assertJsonCount(0, 'resources');
});

test('a non-admin cannot list or update flagged chat messages over http', function () {
    $user = novaNonAdmin();
    $flag = flaggedMessage(User::factory()->create());

    $this->actingAs($user)
        ->getJson('/nova-api/flagged-chat-messages')
        ->assertForbidden();

    $this->actingAs($user)
        ->putJson("/nova-api/flagged-chat-messages/{$flag->id}", [
            'user' => $flag->user_id,
            'conversation' => $flag->conversation_id,
            'flag_type' => 'prompt_injection',
            'reviewed' => true,
        ])
        ->assertForbidden();

    expect($flag->fresh()->reviewed)->toBeFalse();
});
