<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * FILE-001: another user's transaction files cannot be uploaded to, listed,
 * downloaded or deleted. S3 is faked.
 */

beforeEach(function () {
    Storage::fake('s3');
});

test('upload list download and delete on another users transaction files are forbidden and nothing changes', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $owner->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    $attachmentId = $this->actingAs($owner)
        ->post(route('transactions.files.upload', $transaction), [
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nreceipt\n%%EOF"),
        ])
        ->assertCreated()
        ->json('attachment.id');
    $hash = FileAttachment::findOrFail($attachmentId)->file->hash;

    $this->actingAs($intruder)
        ->post(route('transactions.files.upload', $transaction), [
            'file' => UploadedFile::fake()->createWithContent('evil.pdf', "%PDF-1.4\nother\n%%EOF"),
        ])
        ->assertForbidden();

    $this->actingAs($intruder)
        ->getJson(route('transactions.files.index', $transaction))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->get(route('files.download', $attachmentId))
        ->assertForbidden();

    $this->actingAs($intruder)
        ->deleteJson(route('files.delete', $attachmentId))
        ->assertForbidden();

    expect(FileAttachment::count())->toBe(1)
        ->and(File::count())->toBe(1)
        ->and(FileAttachment::find($attachmentId))->not->toBeNull();
    Storage::disk('s3')->assertExists($hash);
});
