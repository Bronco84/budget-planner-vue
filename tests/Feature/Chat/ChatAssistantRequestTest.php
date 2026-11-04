<?php

use App\Models\Budget;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\PrismManager;
use Prism\Prism\Testing\PrismFake;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\SystemMessage;

/*
 * CHAT-001. Request validation, budget scoping, the no-budget prompt, SSE
 * events and provider failure mapping. Prism is always faked.
 */

/** Make every Prism text call throw the given provider exception. */
function failChatProviderWith(Throwable $exception): void
{
    $fake = new class([]) extends PrismFake
    {
        public ?Throwable $exception = null;

        public function text(TextRequest $request): TextResponse
        {
            throw $this->exception;
        }
    };
    $fake->exception = $exception;

    app()->instance(PrismManager::class, new class($fake) extends PrismManager
    {
        public function __construct(private readonly PrismFake $fake) {}

        public function resolve(Provider|string $name, array $providerConfig = []): \Prism\Prism\Providers\Provider
        {
            return $this->fake;
        }
    });
}

/** The system prompt the model received in the given request. */
function chatSystemPrompt(TextRequest $request): string
{
    return collect($request->systemPrompts())
        ->merge(collect($request->messages())->filter(fn ($m) => $m instanceof SystemMessage))
        ->map(fn (SystemMessage $m) => $m->content)
        ->implode("\n");
}

test('chat messages are validated before anything is stored or sent to the provider', function (array $payload, string $errorKey) {
    $fake = Prism::fake([TextResponseFake::make()->withText('Should never be generated.')]);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('chat.send'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    $this->actingAs($user)->postJson(route('chat.stream.start'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect(ChatMessage::count())->toBe(0)
        ->and(ChatConversation::count())->toBe(0);
    $fake->assertCallCount(0);
})->with([
    'empty message' => [['message' => ''], 'message'],
    'message over 2000 characters' => [['message' => str_repeat('a', 2001)], 'message'],
    'unknown conversation' => [['message' => 'Hi', 'conversation_id' => 999999], 'conversation_id'],
    'unknown budget' => [['message' => 'Hi', 'budget_id' => 999999], 'budget_id'],
]);

test('passing another users budget id does not expose that budget to the assistant', function () {
    $fake = Prism::fake([TextResponseFake::make()->withText('Here is your answer.')]);
    $user = User::factory()->create();
    $victimBudget = Budget::factory()->create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Victim Secret Budget',
    ]);

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'What are my accounts?', 'budget_id' => $victimBudget->id])
        ->assertOk();

    $fake->assertRequest(function (array $requests) {
        expect($requests)->toHaveCount(1)
            ->and($requests[0]->tools())->toBe([])
            ->and(chatSystemPrompt($requests[0]))->not->toContain('Victim Secret Budget')
            ->and(chatSystemPrompt($requests[0]))->toContain("hasn't set up a budget yet");
    });
});

test('a user without a budget gets no tools and a getting started prompt', function () {
    $fake = Prism::fake([TextResponseFake::make()->withText('Create a budget to begin.')]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'How do I start?'])
        ->assertOk()
        ->assertJson(['success' => true, 'message' => 'Create a budget to begin.']);

    $fake->assertRequest(function (array $requests) {
        $prompt = chatSystemPrompt($requests[0]);

        expect($requests[0]->tools())->toBe([])
            ->and($prompt)->toContain("The user hasn't set up a budget yet, so no financial data tools are available.")
            ->and($prompt)->toContain('Help them understand how to get started with the application.')
            ->and($prompt)->not->toContain('=== USING YOUR TOOLS ===');
    });
});

test('the stream emits the conversation id, a text delta per word and a stream end event', function () {
    Prism::fake([TextResponseFake::make()->withText('You spent $200.')]);
    $user = User::factory()->create();

    $body = streamChat($this->actingAs($user), ['message' => 'How much did I spend?'])
        ->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->streamedContent();

    $conversation = ChatConversation::where('user_id', $user->id)->sole();

    preg_match_all("/event: (\\w+)\ndata: (.*)\n\n/", $body, $matches, PREG_SET_ORDER);
    $events = array_map(fn ($m) => [$m[1], json_decode($m[2], true)], $matches);

    expect($events)->toBe([
        ['conversation_id', ['conversation_id' => $conversation->id]],
        ['text_delta', ['delta' => 'You']],
        ['text_delta', ['delta' => ' spent']],
        ['text_delta', ['delta' => ' $200.']],
        ['stream_end', ['finish_reason' => 'complete']],
    ]);
});

test('provider failures on send return the specific safe message and log the real cause', function (Throwable $exception, string $message) {
    Log::spy();
    failChatProviderWith($exception);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('chat.send'), ['message' => 'How much did I spend?'])
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'error' => $message]);

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($log, $context) => $log === 'Chat service error'
            && $context['exception'] === $exception
            && $context['user_id'] === $user->id)
        ->once();
})->with([
    'rate limited' => [fn () => PrismRateLimitedException::make(), 'The AI assistant is temporarily unavailable because the provider limit was reached. Please try again in a few minutes.'],
    'overloaded' => [fn () => PrismProviderOverloadedException::make(Provider::OpenAI), 'The AI provider is overloaded right now. Please try again in a moment.'],
    'request too large' => [fn () => PrismRequestTooLargeException::make(Provider::OpenAI), 'That conversation is too long for the assistant to process. Start a new conversation and try again.'],
    'unknown failure' => [fn () => new RuntimeException('provider internals'), 'Something went wrong generating a response. Please try again.'],
]);

test('provider failures while streaming send the specific safe message in band and log the real cause', function (Throwable $exception, string $message) {
    Log::spy();
    failChatProviderWith($exception);
    $user = User::factory()->create();

    $body = streamChat($this->actingAs($user), ['message' => 'How much did I spend?'])
        ->assertOk()
        ->streamedContent();

    expect($body)->toContain("event: error\ndata: ".json_encode(['error' => $message]))
        ->and($body)->not->toContain('stream_end');

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($log, $context) => $log === 'Chat streaming provider error'
            && $context['exception'] === $exception)
        ->once();
})->with([
    'overloaded' => [fn () => PrismProviderOverloadedException::make(Provider::OpenAI), 'The AI provider is overloaded right now. Please try again in a moment.'],
    'request too large' => [fn () => PrismRequestTooLargeException::make(Provider::OpenAI), 'That conversation is too long for the assistant to process. Start a new conversation and try again.'],
]);
