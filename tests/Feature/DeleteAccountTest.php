<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\File;
use App\Models\FileAttachment;
use App\Models\MagicLinkToken;
use App\Models\PlaidConnection;
use App\Models\Scenario;
use App\Models\Transaction;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\BudgetSharingService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\FakePlaidApi;

/*
 * PROFILE-002. Accounts are passkey-only, so deletion is confirmed by typing the
 * account email, and only in a session that authenticated recently.
 */

function passkeyOnlyUser(): User
{
    $user = User::factory()->create(['password' => null]);
    createPasskey($user);

    return $user;
}

function freshlyAuthenticated(): array
{
    return ['auth' => ['authenticated_at' => now()->getTimestamp()]];
}

test('a passkey only user can delete their account by typing their email in a fresh session', function () {
    $user = passkeyOnlyUser();
    TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Laptop',
        'device_fingerprint' => Str::random(40),
        'device_token' => Crypt::encryptString(Str::random(64)),
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);
    $user->magicLinkTokens()->create(['token' => hash('sha256', Str::random(64)), 'expires_at' => now()->addMinutes(15)]);

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->delete(route('profile.destroy'), ['email' => strtoupper($user->email)])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    expect(User::find($user->id))->toBeNull()
        ->and(Passkey::where('user_id', $user->id)->count())->toBe(0)
        ->and(TrustedDevice::where('user_id', $user->id)->count())->toBe(0)
        ->and(MagicLinkToken::where('user_id', $user->id)->count())->toBe(0);
});

test('deleting an account also removes the users legacy laragear credentials', function () {
    $user = passkeyOnlyUser();
    $other = passkeyOnlyUser();
    legacyWebAuthnRow($user, 'legacy-mine');
    legacyWebAuthnRow($other, 'legacy-theirs');

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertRedirect('/');

    expect(DB::table('webauthn_credentials')->pluck('id')->all())->toBe(['legacy-theirs'])
        ->and(Passkey::where('user_id', $other->id)->count())->toBe(1);
});

test('deleting an account clears the trusted device cookie', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->withUnencryptedCookie('device_token', Str::random(64))
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertRedirect('/')
        ->assertCookieExpired('device_token');
});

test('account deletion is rejected when the typed email does not match', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['email' => 'someone-else@example.com'])
        ->assertSessionHasErrors('email')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    expect(User::find($user->id))->not->toBeNull()
        ->and($user->passkeys()->count())->toBe(1);
});

test('account deletion is rejected when the session authenticated more than the reauth window ago', function () {
    $user = passkeyOnlyUser();
    $session = freshlyAuthenticated();

    $this->travel(16)->minutes();

    $this->actingAs($user)
        ->withSession($session)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'For your security, sign in again before deleting your account.'])
        ->assertRedirect(route('profile.edit'));

    expect(User::find($user->id))->not->toBeNull();
});

test('account deletion is rejected when the session has no recorded sign in time', function () {
    $user = passkeyOnlyUser();

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasErrors(['email' => 'For your security, sign in again before deleting your account.']);

    expect(User::find($user->id))->not->toBeNull();
});

test('a guest cannot delete an account', function () {
    $user = passkeyOnlyUser();

    $this->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertRedirect(route('login'));

    expect(User::find($user->id))->not->toBeNull();
});

test('signing in with a magic link records the authentication time used by account deletion', function () {
    $user = passkeyOnlyUser();
    $raw = Str::random(64);
    $user->magicLinkTokens()->create(['token' => hash('sha256', $raw), 'expires_at' => now()->addMinutes(15)]);

    $this->get(route('magic-link.authenticate', ['token' => $raw, 'email' => $user->email]));

    $this->assertAuthenticatedAs($user);
    expect(session('auth.authenticated_at'))->toBe(now()->getTimestamp());

    $this->delete(route('profile.destroy'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    expect(User::find($user->id))->toBeNull();
});

test('a trusted device auto login records the authentication time', function () {
    $user = passkeyOnlyUser();
    $headers = ['User-Agent' => 'Mozilla/5.0 (Macintosh) Chrome/120.0', 'Accept-Language' => 'en-US', 'Accept-Encoding' => 'gzip'];
    $token = Str::random(64);
    TrustedDevice::create([
        'user_id' => $user->id,
        'device_name' => 'Chrome on macOS',
        'device_fingerprint' => hash('sha256', implode('|', array_values($headers))),
        'device_token' => Crypt::encryptString($token),
        'ip_address' => '127.0.0.1',
        'user_agent' => $headers['User-Agent'],
        'last_used_at' => now(),
        'expires_at' => now()->addDays(30),
        'auth_method' => 'passkey',
    ]);

    $this->withHeaders($headers)
        ->withCookie('device_token', $token)
        ->get(route('login'));

    $this->assertAuthenticatedAs($user);
    expect(session('auth.authenticated_at'))->toBe(now()->getTimestamp());
});

/*
 * PROFILE-002 with budgets: shared budgets pass to the longest-standing other
 * owner, unshared budgets are destroyed with their S3 objects and Plaid items,
 * and what the user added to budgets that live on is kept under the budget owner.
 */

function deleteAccountStoredFile(User $user, string $hash): File
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

function deleteAccountAttach(File $file, $attachable, User $user): FileAttachment
{
    return FileAttachment::create([
        'file_id' => $file->id,
        'attachable_type' => $attachable::class,
        'attachable_id' => $attachable->id,
        'attached_by' => $user->id,
    ]);
}

function deleteAccountFor(User $user)
{
    return test()->actingAs($user)
        ->withSession(freshlyAuthenticated())
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), ['email' => $user->email]);
}

test('deleting an account destroys unshared budgets with their S3 objects and Plaid items', function () {
    Storage::fake('s3');
    $plaid = FakePlaidApi::install();
    $plaid->on('/item/remove', ['request_id' => 'req-1']);
    $user = passkeyOnlyUser();
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    deleteAccountAttach(deleteAccountStoredFile($user, 'hash-receipt'), $transaction, $user);
    PlaidConnection::create([
        'budget_id' => $budget->id,
        'plaid_item_id' => 'item-solo',
        'institution_name' => 'First Platypus Bank',
        'access_token' => 'access-item-solo',
        'status' => PlaidConnection::STATUS_ACTIVE,
    ]);

    deleteAccountFor($user)->assertSessionHasNoErrors()->assertRedirect('/');

    expect(User::find($user->id))->toBeNull()
        ->and(Budget::find($budget->id))->toBeNull()
        ->and(File::count())->toBe(0)
        ->and(FileAttachment::count())->toBe(0)
        ->and($plaid->bodyFor('/item/remove'))->toBe(['access_token' => 'access-item-solo']);
    Storage::disk('s3')->assertMissing('hash-receipt');
});

test('deleting an account hands a shared budget to the longest-standing other owner and keeps the users files on it', function () {
    Storage::fake('s3');
    FakePlaidApi::install();
    $alice = passkeyOnlyUser();
    $bob = User::factory()->create();
    $carol = User::factory()->create();
    $dave = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $alice->id]);
    $budget->connected_users()->attach($carol->id, ['access_level' => 'editor']);
    $this->travel(1)->minutes();
    $budget->connected_users()->attach($bob->id, ['access_level' => 'owner']);
    $this->travel(1)->minutes();
    $budget->connected_users()->attach($dave->id, ['access_level' => 'owner']);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = deleteAccountStoredFile($alice, 'hash-alice-receipt');
    $attachment = deleteAccountAttach($receipt, $transaction, $alice);
    $scenario = Scenario::create(['budget_id' => $budget->id, 'user_id' => $alice->id, 'name' => 'Raise']);

    deleteAccountFor($alice)->assertSessionHasNoErrors()->assertRedirect('/');

    $budget->refresh();
    expect(User::find($alice->id))->toBeNull()
        ->and($budget->user_id)->toBe($bob->id)
        ->and($budget->connected_users()->pluck('users.id')->sort()->values()->all())->toBe([$carol->id, $dave->id])
        ->and(Transaction::find($transaction->id))->not->toBeNull()
        ->and($receipt->fresh()->uploaded_by)->toBe($bob->id)
        ->and($attachment->fresh()->attached_by)->toBe($bob->id)
        ->and($scenario->fresh()->user_id)->toBe($bob->id);
    Storage::disk('s3')->assertExists('hash-alice-receipt');
});

test('account deletion is blocked while the user is the last owner of a shared budget', function () {
    $alice = passkeyOnlyUser();
    $carol = User::factory()->create();
    $budget = Budget::factory()->create(['user_id' => $alice->id, 'name' => 'Household']);
    $budget->connected_users()->attach($carol->id, ['access_level' => 'editor']);
    $solo = Budget::factory()->create(['user_id' => $alice->id]);

    deleteAccountFor($alice)
        ->assertSessionHasErrors(['email' => 'You are the last owner of "Household". Make another member an owner, or delete the budget, before deleting your account.'])
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($alice);
    expect(User::find($alice->id))->not->toBeNull()
        ->and(Budget::find($budget->id))->not->toBeNull()
        ->and(Budget::find($solo->id))->not->toBeNull()
        ->and($budget->connected_users()->count())->toBe(1);
});

test('a member deleting their account leaves their receipts and scenarios on the owners budget', function () {
    Storage::fake('s3');
    FakePlaidApi::install();
    $alice = User::factory()->create();
    $carol = passkeyOnlyUser();
    $budget = Budget::factory()->create(['user_id' => $alice->id]);
    $budget->connected_users()->attach($carol->id, ['access_level' => 'editor']);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = deleteAccountStoredFile($carol, 'hash-carol-receipt');
    $receiptAttachment = deleteAccountAttach($receipt, $transaction, $carol);
    // Carol uploaded content Alice already has a record for: it merges into Alice's.
    $alicesCopy = deleteAccountStoredFile($alice, 'hash-same');
    deleteAccountAttach($alicesCopy, $budget, $alice);
    $duplicate = deleteAccountStoredFile($carol, 'hash-same');
    $duplicateAttachment = deleteAccountAttach($duplicate, $transaction, $carol);
    $collidingAttachment = deleteAccountAttach($duplicate, $budget, $carol);
    $unattached = deleteAccountStoredFile($carol, 'hash-unattached');
    $scenario = Scenario::create(['budget_id' => $budget->id, 'user_id' => $carol->id, 'name' => 'Trip']);

    deleteAccountFor($carol)->assertSessionHasNoErrors()->assertRedirect('/');

    expect(User::find($carol->id))->toBeNull()
        ->and($budget->fresh()->user_id)->toBe($alice->id)
        ->and($budget->connected_users()->count())->toBe(0)
        ->and($receipt->fresh()->uploaded_by)->toBe($alice->id)
        ->and($receiptAttachment->fresh()->attached_by)->toBe($alice->id)
        ->and(File::find($duplicate->id))->toBeNull()
        ->and($duplicateAttachment->fresh()->file_id)->toBe($alicesCopy->id)
        ->and($duplicateAttachment->fresh()->attached_by)->toBe($alice->id)
        ->and(FileAttachment::find($collidingAttachment->id))->toBeNull()
        ->and(File::find($unattached->id))->toBeNull()
        ->and($scenario->fresh()->user_id)->toBe($alice->id);
    Storage::disk('s3')->assertExists('hash-carol-receipt');
    Storage::disk('s3')->assertExists('hash-same');
    Storage::disk('s3')->assertMissing('hash-unattached');
});

test('a removed member deleting their account leaves their receipts and scenarios on the owners budget', function () {
    Storage::fake('s3');
    FakePlaidApi::install();
    $alice = User::factory()->create();
    $carol = passkeyOnlyUser();
    $budget = Budget::factory()->create(['user_id' => $alice->id]);
    $budget->connected_users()->attach($carol->id, ['access_level' => 'editor']);
    $account = Account::factory()->checking()->create(['budget_id' => $budget->id]);
    $transaction = Transaction::factory()->create(['budget_id' => $budget->id, 'account_id' => $account->id]);
    $receipt = deleteAccountStoredFile($carol, 'hash-carol-receipt');
    $receiptAttachment = deleteAccountAttach($receipt, $transaction, $carol);
    $budgetAttachment = deleteAccountAttach($receipt, $budget, $carol);
    $scenario = Scenario::create(['budget_id' => $budget->id, 'user_id' => $carol->id, 'name' => 'Trip']);

    app(BudgetSharingService::class)->removeMember($budget, $carol);

    deleteAccountFor($carol)->assertSessionHasNoErrors()->assertRedirect('/');

    expect(User::find($carol->id))->toBeNull()
        ->and($receipt->fresh()->uploaded_by)->toBe($alice->id)
        ->and($receiptAttachment->fresh()?->attached_by)->toBe($alice->id)
        ->and($budgetAttachment->fresh()?->attached_by)->toBe($alice->id)
        ->and($scenario->fresh()?->user_id)->toBe($alice->id);
    Storage::disk('s3')->assertExists('hash-carol-receipt');
});

test('deleting an account erases the activity history of its destroyed budgets and keeps that of shared ones', function () {
    Storage::fake('s3');
    FakePlaidApi::install();
    $alice = passkeyOnlyUser();
    $bob = User::factory()->create();
    $solo = Budget::factory()->create(['user_id' => $alice->id]);
    $soloAccount = Account::factory()->checking()->create(['budget_id' => $solo->id]);
    $soloTransaction = Transaction::factory()->create(['budget_id' => $solo->id, 'account_id' => $soloAccount->id]);
    deleteAccountAttach(deleteAccountStoredFile($alice, 'hash-solo'), $soloTransaction, $alice);
    $shared = Budget::factory()->create(['user_id' => $alice->id]);
    $shared->connected_users()->attach($bob->id, ['access_level' => 'owner']);
    $sharedAccount = Account::factory()->checking()->create(['budget_id' => $shared->id]);
    $sharedTransaction = Transaction::factory()->create(['budget_id' => $shared->id, 'account_id' => $sharedAccount->id]);
    $sharedReceipt = deleteAccountStoredFile($alice, 'hash-shared');
    $sharedAttachment = deleteAccountAttach($sharedReceipt, $sharedTransaction, $alice);
    $kept = Activity::query()
        ->where(fn ($query) => $query->whereMorphedTo('subject', $sharedTransaction)
            ->orWhereMorphedTo('subject', $sharedAttachment)
            ->orWhereMorphedTo('subject', $sharedReceipt))
        ->pluck('id')->sort()->values()->all();
    expect($kept)->toHaveCount(3);

    deleteAccountFor($alice)->assertSessionHasNoErrors()->assertRedirect('/');

    expect(Activity::pluck('id')->sort()->values()->all())->toBe($kept)
        ->and(Activity::whereMorphedTo('subject', $soloTransaction)->exists())->toBeFalse();
});
