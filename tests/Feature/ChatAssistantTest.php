<?php

use App\Models\Budget;
use App\Models\User;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

test('chat assistant returns and persists a reply via the tool-loop flow', function () {
    Prism::fake([
        TextResponseFake::make()->withText('You spent $200 on groceries last month.'),
    ]);

    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->postJson('/chat/message', [
        'message' => 'How much did I spend on groceries?',
        'budget_id' => $budget->id,
    ]);

    $response->assertOk()
        ->assertJson(['success' => true]);

    expect($response->json('message'))->toContain('groceries');

    $this->assertDatabaseHas('chat_messages', ['role' => 'assistant']);
    $this->assertDatabaseHas('chat_messages', ['role' => 'user']);
});
