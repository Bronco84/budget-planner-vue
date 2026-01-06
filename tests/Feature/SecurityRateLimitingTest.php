<?php

use App\Models\User;
use App\Models\Budget;
use App\Models\Account;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->account = Account::factory()->create(['budget_id' => $this->budget->id]);
});

test('file upload endpoint has rate limiting', function () {
    $this->actingAs($this->user);
    
    // Make 11 requests (limit is 10 per minute)
    for ($i = 0; $i < 11; $i++) {
        $file = UploadedFile::fake()->create('document.pdf', 100);
        
        $response = $this->post(
            route('budgets.files.upload', $this->budget),
            ['file' => $file]
        );
        
        if ($i < 10) {
            // First 10 should succeed or fail for other reasons (not rate limit)
            expect($response->status())->not->toBe(429);
        } else {
            // 11th request should be rate limited
            $response->assertStatus(429);
        }
    }
});

test('plaid sync endpoint has rate limiting', function () {
    $this->actingAs($this->user);
    
    // Make 6 requests (limit is 5 per minute)
    for ($i = 0; $i < 6; $i++) {
        $response = $this->post(
            route('plaid.sync', [$this->budget, $this->account])
        );
        
        if ($i < 5) {
            // First 5 should not be rate limited (may fail for other reasons)
            expect($response->status())->not->toBe(429);
        } else {
            // 6th request should be rate limited
            $response->assertStatus(429);
        }
    }
});

test('plaid sync-all endpoint has stricter rate limiting', function () {
    $this->actingAs($this->user);
    
    // Make 4 requests (limit is 3 per minute)
    for ($i = 0; $i < 4; $i++) {
        $response = $this->post(
            route('plaid.sync-all', $this->budget)
        );
        
        if ($i < 3) {
            // First 3 should not be rate limited
            expect($response->status())->not->toBe(429);
        } else {
            // 4th request should be rate limited
            $response->assertStatus(429);
        }
    }
});

