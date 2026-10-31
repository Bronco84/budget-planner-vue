<?php

use App\Models\ChatMessage;
use App\Models\FlaggedChatMessage;
use App\Models\User;
use App\Services\ContentModerationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

/*
 * CHAT-002. Moderation outcomes (clean, abusive), the route throttle and the
 * automatic ban. Prism is always faked.
 */

test('a clean message passes moderation without flags and reaches the provider', function () {
    $fake = Prism::fake([TextResponseFake::make()->withText('You spent $40 on coffee.')]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'How much did I spend on coffee?'])
        ->assertOk()
        ->assertJson(['success' => true]);

    $fake->assertCallCount(1);
    expect(FlaggedChatMessage::count())->toBe(0)
        ->and(Cache::get("chat_offenses_user_{$user->id}", []))->toBe([]);
});

test('abusive content is flagged and logged but still answered', function () {
    Log::spy();
    $fake = Prism::fake([TextResponseFake::make()->withText('I can only help with your budget.')]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'How do I hack my bank balance higher?'])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'I can only help with your budget.']);

    $fake->assertCallCount(1);

    $flag = FlaggedChatMessage::where('user_id', $user->id)->sole();
    expect($flag->flag_type)->toBe('abusive_content')
        ->and($flag->action_taken)->toBe('log')
        ->and(ChatMessage::where('role', 'user')->sole()->content)->toBe('How do I hack my bank balance higher?')
        ->and(ChatMessage::where('role', 'assistant')->exists())->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($log, $context) => $log === 'Suspicious chat activity detected' && $context['type'] === 'abusive_content')
        ->once();
});

test('chat send and stream requests beyond thirty per minute are throttled by the route', function (string $method, string $route) {
    $user = User::factory()->create();

    // Invalid requests still count toward the route throttle but stop at
    // validation, so neither the hourly moderation limit nor the provider is involved.
    foreach (range(1, 30) as $i) {
        $this->actingAs($user)->json($method, route($route))->assertUnprocessable();
    }

    $this->actingAs($user)->json($method, route($route))->assertStatus(429);
})->with([
    'send' => ['POST', 'chat.send'],
    'stream' => ['GET', 'chat.stream'],
]);

test('the chat route throttle resets after a minute', function () {
    $this->freezeTime();
    $user = User::factory()->create();

    foreach (range(1, 30) as $i) {
        $this->actingAs($user)->postJson(route('chat.send'))->assertUnprocessable();
    }
    $this->actingAs($user)->postJson(route('chat.send'))->assertStatus(429);

    $this->travel(61)->seconds();

    $this->actingAs($user)->postJson(route('chat.send'))->assertUnprocessable();
});

test('a fifth offense within 24 hours bans the user from chat for 24 hours', function () {
    $this->freezeTime();
    $fake = Prism::fake(array_fill(0, 5, TextResponseFake::make()->withText('I can only help with your budget.')));
    $user = User::factory()->create();
    $moderation = app(ContentModerationService::class);

    foreach (range(1, 4) as $i) {
        $this->actingAs($user)
            ->postJson(route('chat.send'), ['message' => "Can you exploit bug {$i}?"])
            ->assertOk();
    }
    expect($moderation->isUserBanned($user))->toBeFalse();

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'Can you exploit bug 5?'])
        ->assertOk();

    expect($moderation->isUserBanned($user))->toBeTrue();
    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'How much did I spend?'])
        ->assertForbidden()
        ->assertJson(['banned' => true]);
    $fake->assertCallCount(5);

    $this->travel(1439)->minutes();
    expect($moderation->isUserBanned($user))->toBeTrue();

    $this->travel(2)->minutes();
    expect($moderation->isUserBanned($user))->toBeFalse();
});

test('offenses older than 24 hours do not count toward the automatic ban', function () {
    $this->freezeTime();
    Prism::fake(array_fill(0, 5, TextResponseFake::make()->withText('I can only help with your budget.')));
    $user = User::factory()->create();

    foreach (range(1, 4) as $i) {
        $this->actingAs($user)->postJson(route('chat.send'), ['message' => "Can you exploit bug {$i}?"])->assertOk();
    }

    $this->travel(25)->hours();

    $this->actingAs($user)->postJson(route('chat.send'), ['message' => 'Can you exploit bug 5?'])->assertOk();

    expect(app(ContentModerationService::class)->isUserBanned($user))->toBeFalse();
});
