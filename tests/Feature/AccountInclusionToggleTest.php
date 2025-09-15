<?php

use App\Models\User;
use App\Models\Budget;
use App\Services\VirtualAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('api endpoint accepts integer account ids', function () {
    $this->actingAs($this->user);
    
    $response = $this->post(route('preferences.toggle-account-inclusion'), [
        'account_id' => 123456789,
        'included' => true
    ]);
    
    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['total_included_balance']);
});

test('api endpoint accepts string account ids', function () {
    $this->actingAs($this->user);
    
    $response = $this->post(route('preferences.toggle-account-inclusion'), [
        'account_id' => '123456789',
        'included' => false
    ]);
    
    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['total_included_balance']);
});

test('virtual account service handles type consistency', function () {
    $service = app(VirtualAccountService::class);
    
    // Set with integer, retrieve with string
    $service->setAccountInclusion($this->user->id, 999888, true);
    $result1 = $service->isAccountIncluded($this->user->id, '999888', 'banking');
    expect($result1)->toBeTrue();
    
    // Set with string, retrieve with integer  
    $service->setAccountInclusion($this->user->id, '777666', false);
    $result2 = $service->isAccountIncluded($this->user->id, 777666, 'credit');
    expect($result2)->toBeFalse();
});

test('account settings page can be accessed', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budgets.accounts.index', $this->budget));
    
    $response->assertStatus(200)
        ->assertSee('Account Settings')
        ->assertSee('Manage Account Inclusion');
});

test('budget show page has account settings link', function () {
    $response = $this->actingAs($this->user)
        ->get(route('budgets.show', $this->budget));
    
    $response->assertStatus(200)
        ->assertSee('Account Settings')
        ->assertSee('data-testid="account-settings-link"', false);
});
