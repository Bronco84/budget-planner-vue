<?php

use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * FILE-002: uploading a budget document, the empty files page, and another
 * user's budget files. FileService stores on the s3 disk, which is always faked.
 */

beforeEach(function () {
    Storage::fake('s3');

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

test('uploading a valid pdf or image creates an attachment on the budget', function (callable $makeFile, string $name, string $mime) {
    $upload = $makeFile();

    $response = $this->actingAs($this->user)
        ->post(route('budgets.files.upload', $this->budget), ['file' => $upload, 'description' => 'Statement'])
        ->assertCreated()
        ->assertJsonPath('attachment.file.original_name', $name)
        ->assertJsonPath('attachment.file.mime_type', $mime)
        ->assertJsonPath('attachment.description', 'Statement');

    $attachment = FileAttachment::findOrFail($response->json('attachment.id'));

    expect($attachment->attachable_type)->toBe(Budget::class)
        ->and($attachment->attachable_id)->toBe($this->budget->id)
        ->and($attachment->attached_by)->toBe($this->user->id);

    Storage::disk('s3')->assertExists($attachment->file->hash);
})->with([
    'pdf' => [fn () => UploadedFile::fake()->createWithContent('statement.pdf', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n"), 'statement.pdf', 'application/pdf'],
    'image' => [fn () => UploadedFile::fake()->image('receipt.png'), 'receipt.png', 'image/png'],
]);

test('a budget with no files renders an empty files list', function () {
    $this->actingAs($this->user)
        ->get(route('budgets.files.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Budgets/Files/Index')
            ->has('files', 0));
});

test('another users budget files cannot be listed, uploaded, downloaded or deleted', function () {
    $owner = User::factory()->create();
    $foreignBudget = Budget::factory()->create(['user_id' => $owner->id]);
    Storage::disk('s3')->put('hash-foreign', 'secret');
    $file = File::create([
        'hash' => 'hash-foreign',
        'original_name' => 'secret.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 6,
        'extension' => 'pdf',
        'uploaded_by' => $owner->id,
    ]);
    $attachment = FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => Budget::class,
        'attachable_id' => $foreignBudget->id,
        'attached_by' => $owner->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('budgets.files.index', $foreignBudget))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->getJson(route('budgets.files.index', $foreignBudget))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->get(route('budgets.files.download', [$foreignBudget, $attachment]))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->delete(route('budgets.files.destroy', [$foreignBudget, $attachment]))
        ->assertForbidden();

    $this->actingAs($this->user)
        ->post(route('budgets.files.upload', $foreignBudget), ['file' => UploadedFile::fake()->image('x.png')])
        ->assertForbidden();

    expect(FileAttachment::where('attachable_id', $foreignBudget->id)->count())->toBe(1)
        ->and(FileAttachment::find($attachment->id))->not->toBeNull();
    Storage::disk('s3')->assertExists('hash-foreign');
});
