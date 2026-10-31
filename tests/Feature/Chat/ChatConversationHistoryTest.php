<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

/*
 * CHAT-003. Listing, reopening and deleting chat conversations, scoped to
 * their owner.
 */

function historyConversation(User $user, string $title, array $messages = ['Hello']): ChatConversation
{
    $conversation = ChatConversation::create(['user_id' => $user->id, 'title' => $title]);

    foreach ($messages as $i => $content) {
        ChatMessage::create([
            'conversation_id' => $conversation->id,
            'role' => $i % 2 === 0 ? 'user' : 'assistant',
            'content' => $content,
        ]);
    }

    return $conversation;
}

test('the conversation list shows only the users own conversations, most recent first', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $older = historyConversation($user, 'Older', ['Q1', 'A1']);
    $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();
    $newer = historyConversation($user, 'Newer', ['Q2']);
    historyConversation(User::factory()->create(), 'Not mine');

    $this->actingAs($user)
        ->getJson(route('chat.conversations'))
        ->assertOk()
        ->assertJsonCount(2, 'conversations')
        ->assertJsonPath('conversations.0.id', $newer->id)
        ->assertJsonPath('conversations.0.title', 'Newer')
        ->assertJsonPath('conversations.0.message_count', 1)
        ->assertJsonPath('conversations.1.id', $older->id)
        ->assertJsonPath('conversations.1.message_count', 2)
        ->assertJsonMissing(['title' => 'Not mine']);
});

test('the conversation list is limited to the 20 most recent conversations', function () {
    $user = User::factory()->create();
    foreach (range(1, 22) as $i) {
        historyConversation($user, "Conversation {$i}");
    }

    $this->actingAs($user)
        ->getJson(route('chat.conversations'))
        ->assertOk()
        ->assertJsonCount(20, 'conversations');
});

test('a user with no conversations gets an empty list', function () {
    historyConversation(User::factory()->create(), 'Not mine');

    $this->actingAs(User::factory()->create())
        ->getJson(route('chat.conversations'))
        ->assertOk()
        ->assertExactJson(['conversations' => []]);
});

test('reopening a conversation returns its messages in order', function () {
    $user = User::factory()->create();
    $conversation = historyConversation($user, 'Groceries', ['How much on groceries?', 'You spent $200.', 'And last month?']);

    $this->actingAs($user)
        ->getJson(route('chat.conversations.show', $conversation->id))
        ->assertOk()
        ->assertJsonPath('conversation.id', $conversation->id)
        ->assertJsonPath('conversation.title', 'Groceries')
        ->assertJsonCount(3, 'conversation.messages')
        ->assertJsonPath('conversation.messages.0.role', 'user')
        ->assertJsonPath('conversation.messages.0.content', 'How much on groceries?')
        ->assertJsonPath('conversation.messages.1.role', 'assistant')
        ->assertJsonPath('conversation.messages.1.content', 'You spent $200.')
        ->assertJsonPath('conversation.messages.2.content', 'And last month?');
});

test('an untitled conversation is shown as a new conversation', function () {
    $user = User::factory()->create();
    $conversation = ChatConversation::create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson(route('chat.conversations.show', $conversation->id))
        ->assertOk()
        ->assertJsonPath('conversation.title', 'New Conversation');
});

test('deleting a conversation removes it and its messages', function () {
    $user = User::factory()->create();
    $conversation = historyConversation($user, 'Delete me', ['Q', 'A']);

    $this->actingAs($user)
        ->deleteJson(route('chat.conversations.destroy', $conversation->id))
        ->assertOk()
        ->assertJson(['message' => 'Conversation deleted successfully']);

    expect(ChatConversation::find($conversation->id))->toBeNull()
        ->and(ChatMessage::where('conversation_id', $conversation->id)->count())->toBe(0);
});

test('bulk delete removes the selected conversations and reports the count', function () {
    $user = User::factory()->create();
    $first = historyConversation($user, 'First');
    $second = historyConversation($user, 'Second');
    $kept = historyConversation($user, 'Kept');

    $this->actingAs($user)
        ->postJson(route('chat.conversations.bulk-destroy'), ['conversation_ids' => [$first->id, $second->id]])
        ->assertOk()
        ->assertExactJson(['message' => 'Successfully deleted 2 conversation(s)', 'deleted_count' => 2]);

    expect(ChatConversation::where('user_id', $user->id)->pluck('id')->all())->toBe([$kept->id]);
});

test('bulk delete rejects an empty or unknown id list', function (array $payload, string $errorKey) {
    $user = User::factory()->create();
    $conversation = historyConversation($user, 'Kept');

    $this->actingAs($user)
        ->postJson(route('chat.conversations.bulk-destroy'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect(ChatConversation::find($conversation->id))->not->toBeNull();
})->with([
    'missing list' => [[], 'conversation_ids'],
    'empty list' => [['conversation_ids' => []], 'conversation_ids'],
    'unknown id' => [['conversation_ids' => [999999]], 'conversation_ids.0'],
    'non-integer id' => [['conversation_ids' => ['abc']], 'conversation_ids.0'],
]);

test('another users conversation cannot be shown or deleted, and bulk delete skips it', function () {
    $owner = User::factory()->create();
    $victim = historyConversation($owner, 'Private', ['Secret question']);
    $intruder = User::factory()->create();
    $own = historyConversation($intruder, 'Mine');

    $this->actingAs($intruder)
        ->getJson(route('chat.conversations.show', $victim->id))
        ->assertNotFound()
        ->assertJsonMissing(['content' => 'Secret question']);

    $this->actingAs($intruder)
        ->deleteJson(route('chat.conversations.destroy', $victim->id))
        ->assertNotFound();

    $this->actingAs($intruder)
        ->postJson(route('chat.conversations.bulk-destroy'), ['conversation_ids' => [$victim->id, $own->id]])
        ->assertOk()
        ->assertJson(['deleted_count' => 1]);

    expect(ChatConversation::find($victim->id))->not->toBeNull()
        ->and($victim->messages()->count())->toBe(1)
        ->and(ChatConversation::find($own->id))->toBeNull();
});

test('guests cannot list, show or delete conversations', function () {
    $conversation = historyConversation(User::factory()->create(), 'Private');

    $this->getJson(route('chat.conversations'))->assertUnauthorized();
    $this->getJson(route('chat.conversations.show', $conversation->id))->assertUnauthorized();
    $this->deleteJson(route('chat.conversations.destroy', $conversation->id))->assertUnauthorized();
    $this->postJson(route('chat.conversations.bulk-destroy'), ['conversation_ids' => [$conversation->id]])->assertUnauthorized();

    expect(ChatConversation::find($conversation->id))->not->toBeNull();
});
