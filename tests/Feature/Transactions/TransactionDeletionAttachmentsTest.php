<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * PR-077. Attachments are polymorphic, so deleting a transaction by any path
 * (Nova included) must remove them and the files and S3 objects nothing else uses.
 */

function deletionAttachmentsReceipt(Transaction $transaction, User $user, string $hash): FileAttachment
{
    Storage::disk('s3')->put($hash, 'content-'.$hash);
    $file = File::firstOrCreate(['hash' => $hash, 'uploaded_by' => $user->id], [
        'original_name' => $hash.'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'extension' => 'pdf',
    ]);

    return FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => Transaction::class,
        'attachable_id' => $transaction->id,
        'attached_by' => $user->id,
    ]);
}

test('deleting a transaction from nova removes its attachments, unused files and S3 objects', function () {
    Storage::fake('s3');
    $admin = User::factory()->create();
    $admin->is_admin = true;
    $admin->save();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $other = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = deletionAttachmentsReceipt($transaction, $admin, 'hash-only-here');
    $shared = deletionAttachmentsReceipt($transaction, $admin, 'hash-shared');
    $kept = deletionAttachmentsReceipt($other, $admin, 'hash-shared');

    $this->actingAs($admin)
        ->deleteJson('/nova-api/transactions?resources[]='.$transaction->id)
        ->assertOk();

    expect(Transaction::find($transaction->id))->toBeNull()
        ->and(FileAttachment::find($receipt->id))->toBeNull()
        ->and(FileAttachment::find($shared->id))->toBeNull()
        ->and(File::find($receipt->file_id))->toBeNull()
        ->and(FileAttachment::find($kept->id))->not->toBeNull()
        ->and(File::find($kept->file_id))->not->toBeNull();
    Storage::disk('s3')->assertMissing('hash-only-here');
    Storage::disk('s3')->assertExists('hash-shared');
});

test('a model delete of a transaction removes its attachments wherever it is called from', function () {
    Storage::fake('s3');
    $user = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = deletionAttachmentsReceipt($transaction, $user, 'hash-console');

    $transaction->delete();

    expect(FileAttachment::find($receipt->id))->toBeNull()
        ->and(File::find($receipt->file_id))->toBeNull();
    Storage::disk('s3')->assertMissing('hash-console');
});
