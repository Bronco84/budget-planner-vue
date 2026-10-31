<?php

use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FILE-002: the budget Files page and its download/delete actions. Files are
 * stored on the s3 disk keyed by content hash (FileService); S3 is always faked.
 */

beforeEach(function () {
    Storage::fake('s3');
    Storage::fake(config('filesystems.default'));

    $this->user = User::factory()->create(['name' => 'Budget Owner']);
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function budgetFilesAttachment(Budget $budget, User $user, string $hash = 'hash-statement'): FileAttachment
{
    Storage::disk('s3')->put($hash, 'statement body');

    $file = File::create([
        'hash' => $hash,
        'original_name' => 'statement.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 14,
        'extension' => 'pdf',
        'uploaded_by' => $user->id,
    ]);

    return FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => Budget::class,
        'attachable_id' => $budget->id,
        'attached_by' => $user->id,
    ]);
}

test('the files page renders the budgets files', function () {
    $attachment = budgetFilesAttachment($this->budget, $this->user);

    $this->actingAs($this->user)
        ->get(route('budgets.files.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Files/Index')
            ->where('files.0.id', $attachment->id)
            ->where('files.0.original_filename', 'statement.pdf'));
});

test('the files index returns the attachments as JSON when JSON is requested', function () {
    $attachment = budgetFilesAttachment($this->budget, $this->user);

    $this->actingAs($this->user)
        ->getJson(route('budgets.files.index', $this->budget))
        ->assertOk()
        ->assertJsonPath('attachments.0.id', $attachment->id)
        ->assertJsonPath('attachments.0.file.original_name', 'statement.pdf')
        ->assertJsonPath('attachments.0.attached_by', 'Budget Owner');
});

test('downloading a budget file streams the object stored on the s3 disk', function () {
    $attachment = budgetFilesAttachment($this->budget, $this->user);

    $response = $this->actingAs($this->user)
        ->get(route('budgets.files.download', [$this->budget, $attachment]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    expect($response->getContent())->toBe('statement body')
        ->and($response->headers->get('Content-Disposition'))->toContain('statement.pdf');
});

test('deleting a budget file removes the attachment, file and s3 object', function () {
    $attachment = budgetFilesAttachment($this->budget, $this->user);

    $this->actingAs($this->user)
        ->from(route('budgets.files.index', $this->budget))
        ->delete(route('budgets.files.destroy', [$this->budget, $attachment]))
        ->assertRedirect(route('budgets.files.index', $this->budget))
        ->assertSessionHas('message', 'File deleted successfully.')
        ->assertSessionMissing('success');

    expect(FileAttachment::count())->toBe(0)
        ->and(File::count())->toBe(0);
    Storage::disk('s3')->assertMissing('hash-statement');
});

test('an attachment of another budget is not found under the users own budget', function () {
    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $attachment = budgetFilesAttachment($otherBudget, $this->user);

    $this->actingAs($this->user)
        ->get(route('budgets.files.download', [$this->budget, $attachment]))
        ->assertNotFound();
    $this->actingAs($this->user)
        ->delete(route('budgets.files.destroy', [$this->budget, $attachment]))
        ->assertNotFound();

    expect(FileAttachment::count())->toBe(1);
});
