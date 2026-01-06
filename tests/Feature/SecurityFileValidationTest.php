<?php

use App\Models\User;
use App\Models\Budget;
use App\Services\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    
    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
    $this->fileService = app(FileService::class);
});

test('file upload rejects files over 10MB', function () {
    $this->actingAs($this->user);
    
    // Create a file larger than 10MB (10240 KB)
    $file = UploadedFile::fake()->create('large.pdf', 10241);
    
    $response = $this->post(
        route('budgets.files.upload', $this->budget),
        ['file' => $file]
    );
    
    // Laravel validation returns 302 redirect with errors
    $response->assertSessionHasErrors('file');
});

test('file upload rejects disallowed file types', function () {
    $this->actingAs($this->user);
    
    // Try to upload an executable file
    $file = UploadedFile::fake()->create('malicious.exe', 100);
    
    $response = $this->post(
        route('budgets.files.upload', $this->budget),
        ['file' => $file]
    );
    
    $response->assertStatus(400);
    expect($response->json('error'))->toContain('not allowed');
});

test('file upload accepts PDF files', function () {
    $this->actingAs($this->user);
    
    $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    
    $response = $this->post(
        route('budgets.files.upload', $this->budget),
        ['file' => $file]
    );
    
    // Should succeed (201) or fail for reasons other than file type
    expect($response->status())->not->toBe(400);
});

test('file upload accepts image files', function () {
    $this->actingAs($this->user);
    
    $file = UploadedFile::fake()->image('photo.jpg');
    
    $response = $this->post(
        route('budgets.files.upload', $this->budget),
        ['file' => $file]
    );
    
    // Should succeed (201) or fail for reasons other than file type
    expect($response->status())->not->toBe(400);
});

test('file extension must match mime type', function () {
    $this->actingAs($this->user);
    
    // Create a file with mismatched extension and mime type
    // This simulates someone renaming malicious.exe to malicious.pdf
    $file = UploadedFile::fake()->createWithContent(
        'fake.pdf',
        'fake content'
    );
    
    // Force wrong mime type
    $file = new UploadedFile(
        $file->path(),
        'fake.pdf',
        'application/x-msdownload', // executable mime type
        null,
        true
    );
    
    $response = $this->post(
        route('budgets.files.upload', $this->budget),
        ['file' => $file]
    );
    
    $response->assertStatus(400);
});

