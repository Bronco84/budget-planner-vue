<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\PlaidAccount;
use App\Models\PlaidConnection;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePlaidApi;

/*
 * Deletes through Nova's HTTP API must not skip the app's deletion services.
 * PR-079: users cannot be deleted from Nova at all (they delete their own
 * accounts from Profile, through AccountDeletionService).
 * PR-081: a budget deleted from Nova goes through BudgetDeletionService, with
 * the same cleanup as deleting it in the app, and a file attachment deleted
 * from Nova is detached through FileService.
 * PR-084: an account deleted from Nova gets the app's guards (no transfers, no
 * transactions) and its empty Plaid connection is removed with its Item.
 * PR-085: files cannot be deleted from Nova (delete their attachments instead).
 * PR-086: Plaid connections cannot be deleted from Nova (owners unlink them in
 * the app, which removes the Item at Plaid).
 */

function novaDeletionAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

test('an admin cannot delete a user through nova', function () {
    $admin = novaDeletionAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->deleteJson('/nova-api/users?resources[]='.$user->id)
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/users/force?resources[]='.$user->id);
    $this->actingAs($admin)
        ->deleteJson('/nova-api/users?resources=all')
        ->assertOk();

    expect(User::find($user->id))->not->toBeNull()
        ->and(User::find($admin->id))->not->toBeNull();
});

test('nova does not offer deleting a user', function () {
    $admin = novaDeletionAdmin();
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->getJson('/nova-api/users/'.$user->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToDelete', false)
        ->assertJsonPath('resource.authorizedToForceDelete', false);
});

function novaDeletionStoredFile(User $user, string $hash): File
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

function novaDeletionAttach(File $file, $attachable, User $user): FileAttachment
{
    return FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => $attachable::class,
        'attachable_id' => $attachable->id,
        'attached_by' => $user->id,
    ]);
}

test('deleting a budget from nova cleans up like deleting it in the app', function () {
    Storage::fake('s3');
    $plaid = FakePlaidApi::install();
    $plaid->on('/item/remove', ['request_id' => 'req-1']);
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    novaDeletionAttach(novaDeletionStoredFile($admin, 'hash-budget'), $budget, $admin);
    novaDeletionAttach(novaDeletionStoredFile($admin, 'hash-receipt'), $transaction, $admin);
    PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-one',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-one',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    activity()->performedOn($budget)->causedBy($admin)->log('File attached to budget');

    $otherBudget = Budget::factory()->create(['user_id' => $admin->id]);
    $shared = novaDeletionStoredFile($admin, 'hash-shared');
    novaDeletionAttach($shared, $budget, $admin);
    $kept = novaDeletionAttach($shared, $otherBudget, $admin);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/budgets?resources[]='.$budget->id)
        ->assertOk();

    expect(Budget::find($budget->id))->toBeNull()
        ->and(Budget::find($otherBudget->id))->not->toBeNull()
        ->and(FileAttachment::pluck('id')->all())->toBe([$kept->id])
        ->and(File::pluck('hash')->all())->toBe(['hash-shared'])
        ->and(PlaidConnection::withTrashed()->where('budget_id', $budget->id)->count())->toBe(0)
        ->and(Activity::where('subject_type', Budget::class)->where('subject_id', $budget->id)->count())->toBe(0)
        ->and(collect($plaid->requests)->where('path', '/item/remove')->pluck('body.access_token')->all())
        ->toBe(['access-item-one']);
    Storage::disk('s3')->assertMissing('hash-budget');
    Storage::disk('s3')->assertMissing('hash-receipt');
    Storage::disk('s3')->assertExists('hash-shared');
});

test('deleting a file attachment from nova removes a file nothing else uses from S3', function () {
    Storage::fake('s3');
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $only = novaDeletionAttach(novaDeletionStoredFile($admin, 'hash-only'), $budget, $admin);
    $shared = novaDeletionStoredFile($admin, 'hash-shared');
    $sharedHere = novaDeletionAttach($shared, $budget, $admin);
    $kept = novaDeletionAttach($shared, Budget::factory()->create(['user_id' => $admin->id]), $admin);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/file-attachments?resources[]='.$only->id.'&resources[]='.$sharedHere->id)
        ->assertOk();

    expect(FileAttachment::pluck('id')->all())->toBe([$kept->id])
        ->and(File::pluck('hash')->all())->toBe(['hash-shared']);
    Storage::disk('s3')->assertMissing('hash-only');
    Storage::disk('s3')->assertExists('hash-shared');
});

function novaDeletionPlaidAccount(PlaidConnection $connection, Budget $budget, string $plaidId): Account
{
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    PlaidAccount::create([
        'plaid_connection_id' => $connection->id,
        'account_id' => $account->id,
        'plaid_account_id' => $plaidId,
        'account_name' => 'Checking',
        'account_type' => 'depository',
        'account_subtype' => 'checking',
    ]);

    return $account;
}

test('deleting the last account of a plaid connection from nova removes the connection and its plaid item', function () {
    $plaid = FakePlaidApi::install();
    $plaid->on('/item/remove', ['request_id' => 'req-1']);
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-one',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-one',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $account = novaDeletionPlaidAccount($connection, $budget, 'acc-1');

    $this->actingAs($admin)
        ->deleteJson('/nova-api/accounts?resources[]='.$account->id)
        ->assertOk();

    expect(Account::find($account->id))->toBeNull()
        ->and(PlaidAccount::count())->toBe(0)
        ->and(PlaidConnection::find($connection->id))->toBeNull()
        ->and($plaid->bodyFor('/item/remove'))->toBe(['access_token' => 'access-item-one']);
});

test('deleting one of several accounts on a plaid connection from nova keeps the connection', function () {
    $plaid = FakePlaidApi::install();
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-one',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-one',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $account = novaDeletionPlaidAccount($connection, $budget, 'acc-1');
    $kept = novaDeletionPlaidAccount($connection, $budget, 'acc-2');

    $this->actingAs($admin)
        ->deleteJson('/nova-api/accounts?resources[]='.$account->id)
        ->assertOk();

    expect(Account::find($account->id))->toBeNull()
        ->and(Account::find($kept->id))->not->toBeNull()
        ->and(PlaidConnection::find($connection->id))->not->toBeNull()
        ->and(collect($plaid->requests)->where('path', '/item/remove')->all())->toBe([]);
});

test('nova refuses to delete an account with transactions', function () {
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/accounts?resources[]='.$account->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['resources' => 'Cannot delete account with existing transactions.']);

    expect(Account::find($account->id))->not->toBeNull()
        ->and(Transaction::where('account_id', $account->id)->count())->toBe(1);
});

test('nova refuses to delete an account with transfers', function () {
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $other = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    Transfer::create([
        'budget_id' => $budget->id,
        'from_account_id' => $other->id,
        'to_account_id' => $account->id,
        'amount_in_cents' => 1000,
        'date' => now()->toDateString(),
        'description' => 'Move money',
    ]);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/accounts?resources[]='.$account->id)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['resources' => 'This account has transfers.']);

    expect(Account::find($account->id))->not->toBeNull();
});

test('an admin cannot delete a file through nova', function () {
    Storage::fake('s3');
    $admin = novaDeletionAdmin();
    $file = novaDeletionStoredFile($admin, 'hash-kept');
    novaDeletionAttach($file, Budget::factory()->create(['user_id' => $admin->id]), $admin);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/files?resources[]='.$file->id)
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/files?resources=all')
        ->assertOk();

    expect(File::find($file->id))->not->toBeNull()
        ->and(FileAttachment::where('file_id', $file->id)->count())->toBe(1);
    Storage::disk('s3')->assertExists('hash-kept');

    $resource = new App\Nova\File($file);
    expect($resource->authorizedToDelete(request()))->toBeFalse()
        ->and($resource->authorizedToForceDelete(request()))->toBeFalse();
});

test('an admin cannot delete a plaid connection through nova', function () {
    $plaid = FakePlaidApi::install();
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-one',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-one',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-connections?resources[]='.$connection->id)
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-connections?resources=all')
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-connections/force?resources[]='.$connection->id);

    expect(PlaidConnection::find($connection->id))->not->toBeNull()
        ->and(collect($plaid->requests)->where('path', '/item/remove')->all())->toBe([]);

    $this->actingAs($admin)
        ->getJson('/nova-api/plaid-connections/'.$connection->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToDelete', false)
        ->assertJsonPath('resource.authorizedToForceDelete', false);
});

test('an admin cannot delete a plaid account through nova', function () {
    $plaid = FakePlaidApi::install();
    $admin = novaDeletionAdmin();
    $budget = Budget::factory()->create(['user_id' => $admin->id]);
    $connection = PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-one',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-one',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);
    $account = novaDeletionPlaidAccount($connection, $budget, 'acc-1');
    $plaidAccount = PlaidAccount::where('account_id', $account->id)->sole();

    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-accounts?resources[]='.$plaidAccount->id)
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-accounts?resources=all')
        ->assertOk();
    $this->actingAs($admin)
        ->deleteJson('/nova-api/plaid-accounts/force?resources[]='.$plaidAccount->id);

    expect(PlaidAccount::find($plaidAccount->id))->not->toBeNull()
        ->and(PlaidConnection::find($connection->id))->not->toBeNull()
        ->and(collect($plaid->requests)->where('path', '/item/remove')->all())->toBe([]);

    $this->actingAs($admin)
        ->getJson('/nova-api/plaid-accounts/'.$plaidAccount->id)
        ->assertOk()
        ->assertJsonPath('resource.authorizedToDelete', false)
        ->assertJsonPath('resource.authorizedToForceDelete', false);
});
