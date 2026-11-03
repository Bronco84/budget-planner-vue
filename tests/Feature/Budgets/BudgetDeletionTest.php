<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePlaidApi;

/*
 * BUDGET-005: deleting a budget also cleans up what its rows do not cascade to:
 * polymorphic file attachments (and unused S3 objects) and Plaid items at Plaid.
 * S3 and Plaid are always faked.
 */

beforeEach(function () {
    Storage::fake('s3');
    $this->plaid = FakePlaidApi::install();

    $this->user = User::factory()->create();
    $this->budget = Budget::factory()->create(['user_id' => $this->user->id]);
});

function budgetDeletionStoredFile(User $user, string $hash): File
{
    Storage::disk('s3')->put($hash, 'content-'.$hash);

    return File::create([
        'hash' => $hash,
        'original_name' => $hash.'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'extension' => 'pdf',
        'uploaded_by' => $user->id,
    ]);
}

function budgetDeletionAttach(File $file, $attachable, User $user): FileAttachment
{
    return FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => $attachable::class,
        'attachable_id' => $attachable->id,
        'attached_by' => $user->id,
    ]);
}

function budgetDeletionConnection(Budget $budget, string $itemId): PlaidConnection
{
    return PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => $itemId,
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-'.$itemId,
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
}

test('deleting a budget removes its budget and transaction attachments and their unused S3 objects', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $account->id]);
    $budgetFile = budgetDeletionStoredFile($this->user, 'hash-budget');
    $receipt = budgetDeletionStoredFile($this->user, 'hash-receipt');
    budgetDeletionAttach($budgetFile, $this->budget, $this->user);
    budgetDeletionAttach($receipt, $transaction, $this->user);

    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $shared = budgetDeletionStoredFile($this->user, 'hash-shared');
    budgetDeletionAttach($shared, $this->budget, $this->user);
    $keptAttachment = budgetDeletionAttach($shared, $otherBudget, $this->user);

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $this->budget))
        ->assertRedirect(route('budgets.index'));

    expect(Budget::find($this->budget->id))->toBeNull()
        ->and(FileAttachment::pluck('id')->all())->toBe([$keptAttachment->id])
        ->and(File::pluck('hash')->all())->toBe(['hash-shared']);
    Storage::disk('s3')->assertMissing('hash-budget');
    Storage::disk('s3')->assertMissing('hash-receipt');
    Storage::disk('s3')->assertExists('hash-shared');
});

test('deleting a budget removes each of its Plaid items at Plaid', function () {
    budgetDeletionConnection($this->budget, 'item-one');
    budgetDeletionConnection($this->budget, 'item-two');
    $this->plaid->on('/item/remove', ['request_id' => 'req-1']);

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $this->budget))
        ->assertRedirect(route('budgets.index'));

    $removed = collect($this->plaid->requests)
        ->where('path', '/item/remove')
        ->pluck('body.access_token')->sort()->values()->all();
    expect($removed)->toBe(['access-item-one', 'access-item-two'])
        ->and(PlaidConnection::count())->toBe(0);
});

test('a Plaid failure removing an item still deletes the budget locally', function () {
    budgetDeletionConnection($this->budget, 'item-one');
    $this->plaid->on('/item/remove', FakePlaidApi::error('INTERNAL_SERVER_ERROR', 'plaid down', 500));

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $this->budget))
        ->assertRedirect(route('budgets.index'))
        ->assertSessionHas('message', 'Budget deleted successfully');

    expect($this->plaid->paths())->toContain('/item/remove')
        ->and(Budget::find($this->budget->id))->toBeNull()
        ->and(PlaidConnection::count())->toBe(0);
});

test('deleting a budget erases its activity history and keeps the history of live budgets', function () {
    $account = Account::factory()->checking()->create(['budget_id' => $this->budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $this->budget->id, 'account_id' => $account->id]);
    $transaction->update(['notes' => 'Dinner with Sam']);
    $receipt = budgetDeletionStoredFile($this->user, 'hash-receipt');
    budgetDeletionAttach($receipt, $transaction, $this->user);
    $transaction->logActivity('File attached', ['file_name' => 'receipt.pdf'], $this->user->id);
    activity()->performedOn($this->budget)->causedBy($this->user)->log('File attached to budget');
    // An attachment detached before the budget is deleted still leaves history behind.
    $detached = budgetDeletionStoredFile($this->user, 'hash-detached');
    app(FileService::class)->detachFile(budgetDeletionAttach($detached, $this->budget, $this->user), $this->user->id);

    $otherBudget = Budget::factory()->create(['user_id' => $this->user->id]);
    $otherAccount = Account::factory()->checking()->create(['budget_id' => $otherBudget->id]);
    $otherTransaction = Transaction::factory()->create(['budget_id' => $otherBudget->id, 'account_id' => $otherAccount->id]);
    $shared = budgetDeletionStoredFile($this->user, 'hash-shared');
    budgetDeletionAttach($shared, $this->budget, $this->user);
    $otherAttachment = budgetDeletionAttach($shared, $otherBudget, $this->user);
    $kept = Activity::query()
        ->where(fn ($query) => $query->whereMorphedTo('subject', $otherTransaction)
            ->orWhereMorphedTo('subject', $otherAttachment)
            ->orWhereMorphedTo('subject', $shared))
        ->pluck('id')->sort()->values()->all();
    expect($kept)->not->toBeEmpty();

    $this->actingAs($this->user)
        ->delete(route('budgets.destroy', $this->budget))
        ->assertRedirect(route('budgets.index'));

    expect(Activity::pluck('id')->sort()->values()->all())->toBe($kept);
});
