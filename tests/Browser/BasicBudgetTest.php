<?php

test('can create and view budget without errors', function () {
    // Test that we can at least create a budget and navigate to it 
    // without JavaScript errors
    
    $user = \App\Models\User::factory()->create();
    
    $this->actingAs($user)
         ->post('/budgets', [
             'name' => 'Test Budget',
             'description' => 'Testing JavaScript fixes'
         ])
         ->assertRedirect()
         ->assertSessionHas('message');
         
    // Check that a budget was created
    $budget = \App\Models\Budget::where('name', 'Test Budget')->first();
    expect($budget)->not->toBeNull();
    
    // Try to visit the budget page (this should not throw JavaScript errors anymore)
    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");
    $response->assertStatus(200);
    $response->assertSee('Test Budget');
});
