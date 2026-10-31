<?php

use App\Enums\BudgetRole;
use App\Models\Account;
use App\Models\Budget;
use App\Models\FileAttachment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
 * FILE-001 viewer_reads_receipts: a budget viewer sees which transactions have
 * receipts on the transactions list and can list and download them, but cannot
 * upload or delete. S3 is faked.
 */

beforeEach(function () {
    Storage::fake('s3');

    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->owner->id]);
    $this->budget->connected_users()->attach($this->viewer->id, ['access_level' => BudgetRole::Viewer->value]);
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $this->transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $account->id]);
    $this->plainTransaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $account->id]);

    $this->attachmentId = $this->actingAs($this->owner)
        ->post(route('transactions.files.upload', $this->transaction), [
            'file' => UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nreceipt\n%%EOF"),
        ])
        ->assertCreated()
        ->json('attachment.id');
});

test('the transactions list gives each transaction its attachment count', function () {
    $response = $this->actingAs($this->viewer)
        ->get(route('budget.transaction.index', $this->budget))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Transactions/Index')
            ->where('budgetAccess.role', 'viewer')
            ->where('budgetAccess.can.edit', false)
            ->has('transactions.data', 2)
        );

    $rows = collect($response->viewData('page')['props']['transactions']['data'])->keyBy('id');

    expect($rows[$this->transaction->id]['file_attachments_count'])->toBe(1)
        ->and($rows[$this->plainTransaction->id]['file_attachments_count'])->toBe(0);
});

test('a viewer can list and download a transactions receipts', function () {
    $this->actingAs($this->viewer)
        ->getJson(route('transactions.files.index', $this->transaction))
        ->assertOk()
        ->assertJsonCount(1, 'attachments')
        ->assertJsonPath('attachments.0.id', $this->attachmentId)
        ->assertJsonPath('attachments.0.file.original_name', 'receipt.pdf')
        ->assertJsonPath('attachments.0.attached_by', $this->owner->name);

    $this->actingAs($this->viewer)
        ->get(route('files.download', $this->attachmentId))
        ->assertOk();
});

test('a viewer cannot upload or delete receipts', function () {
    $this->actingAs($this->viewer)
        ->post(route('transactions.files.upload', $this->transaction), [
            'file' => UploadedFile::fake()->createWithContent('mine.pdf', "%PDF-1.4\nmine\n%%EOF"),
        ])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->deleteJson(route('files.delete', $this->attachmentId))
        ->assertForbidden();

    expect(FileAttachment::count())->toBe(1)
        ->and(FileAttachment::find($this->attachmentId))->not->toBeNull();
});

test('a non-member cannot list or download a transactions receipts', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->getJson(route('transactions.files.index', $this->transaction))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->get(route('files.download', $this->attachmentId))
        ->assertForbidden();
});
