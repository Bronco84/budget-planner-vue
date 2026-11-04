<?php

use App\Models\User;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Key\OkpKey;
use Cose\Key\RsaKey;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passkeys\Passkey;
use Tests\Support\SoftwareAuthenticator;

/*
 * AUTH-001 / PR-049. Passkeys registered under laragear/webauthn keep working
 * after the move to laravel/passkeys: migration 2026_11_04_000049 copies each
 * webauthn_credentials row into passkeys, and the copy completes a real login
 * ceremony signed by the same authenticator.
 */

beforeEach(fn () => useWebAuthnTestRelyingParty());

function passkeyMoveMigration(): object
{
    return require database_path('migrations/2026_11_04_000049_move_webauthn_credentials_to_passkeys.php');
}

/**
 * Register the authenticator the way laragear did (user.id = the user handle
 * UUID's 32 hex digits, which the browser base64url-decoded) and store the row
 * laragear wrote for it: encrypted SPKI PEM, UUID user handle.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyLaragearCredential(User $user, SoftwareAuthenticator $authenticator, array $overrides = []): string
{
    $handle = (string) Str::uuid();

    $authenticator->attest([
        'challenge' => SoftwareAuthenticator::b64url(random_bytes(16)),
        'rp' => ['name' => 'Budget Planner', 'id' => WEBAUTHN_TEST_RP_ID],
        'user' => ['id' => str_replace('-', '', $handle), 'name' => $user->email, 'displayName' => $user->name],
    ], WEBAUTHN_TEST_ORIGIN);

    DB::table('webauthn_credentials')->insert([
        'id' => $authenticator->credentialId(),
        'authenticatable_type' => User::class,
        'authenticatable_id' => $user->id,
        'user_id' => $handle,
        'alias' => 'Old iPhone',
        'counter' => 0,
        'rp_id' => WEBAUTHN_TEST_RP_ID,
        'origin' => WEBAUTHN_TEST_ORIGIN,
        'transports' => json_encode(['internal', 'hybrid']),
        'aaguid' => 'fbfc3007-154e-4ecc-8c0b-6e020557d7bd',
        'public_key' => Crypt::encryptString($authenticator->publicKeyPem()),
        'attestation_format' => 'none',
        'certificates' => null,
        'disabled_at' => null,
        'created_at' => '2026-02-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
        ...$overrides,
    ]);

    return $handle;
}

/**
 * Run the move as a deploy would: the rows exist before the migration does.
 */
function runPasskeyMove(): void
{
    $migration = passkeyMoveMigration();
    $migration->down();
    $migration->up();
}

test('a passkey stored by laragear still signs the user in after the migration', function () {
    $user = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;
    legacyLaragearCredential($user, $authenticator);

    runPasskeyMove();

    $passkey = Passkey::sole();
    expect($passkey->user_id)->toBe($user->id)
        ->and($passkey->credential_id)->toBe($authenticator->credentialId())
        ->and($passkey->name)->toBe('Old iPhone')
        ->and($passkey->created_at->toDateTimeString())->toBe('2026-02-01 10:00:00')
        ->and($passkey->credential['transports'])->toBe(['internal', 'hybrid'])
        ->and($passkey->credential['aaguid'])->toBe('fbfc3007-154e-4ecc-8c0b-6e020557d7bd')
        ->and(passkeyPublicKeyPem($passkey))->toBe($authenticator->publicKeyPem());

    // A real usernameless assertion signed by the same authenticator, with the
    // user handle laragear issued.
    $options = $this->postJson(route('webauthn.login.options'))->assertOk()->json();
    $assertion = $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN);
    expect($assertion['response']['userHandle'])->not->toBeNull();

    $this->postJson(route('webauthn.login'), $assertion)->assertNoContent();

    $this->assertAuthenticatedAs($user);
    expect($passkey->fresh()->credential['counter'])->toBe(1)
        ->and($passkey->fresh()->last_used_at)->not->toBeNull();

    // A second sign-in from the migrated passkey works too (counter advanced).
    Auth::forgetGuards();
    session()->flush();
    $options = $this->postJson(route('webauthn.login.options'))->assertOk()->json();
    $this->postJson(route('webauthn.login'), $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN))->assertNoContent();
    $this->assertAuthenticatedAs($user);
});

test('a migrated passkey still rejects a signature from a different key', function () {
    $user = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;
    legacyLaragearCredential($user, $authenticator);
    runPasskeyMove();

    $impostor = new SoftwareAuthenticator(SoftwareAuthenticator::b64urlDecode($authenticator->credentialId()));
    $options = $this->postJson(route('webauthn.login.options'))->json();

    $this->postJson(route('webauthn.login'), $impostor->assert($options, WEBAUTHN_TEST_ORIGIN))->assertStatus(422);
    $this->assertGuest();
});

test('the migration carries the sign counter so a replayed lower counter is still refused', function () {
    $user = User::factory()->create();
    $authenticator = new SoftwareAuthenticator;
    legacyLaragearCredential($user, $authenticator, ['counter' => 5]);
    runPasskeyMove();

    expect(Passkey::sole()->credential['counter'])->toBe(5);

    // The authenticator's next counter (1) is below the stored 5.
    $options = $this->postJson(route('webauthn.login.options'))->json();
    $this->postJson(route('webauthn.login'), $authenticator->assert($options, WEBAUTHN_TEST_ORIGIN))->assertStatus(422);
    $this->assertGuest();
});

test('disabled, orphaned and unreadable laragear rows are skipped and the rest are copied', function () {
    Log::spy();
    $user = User::factory()->create();
    $good = new SoftwareAuthenticator;
    legacyLaragearCredential($user, $good);
    legacyLaragearCredential($user, new SoftwareAuthenticator, ['disabled_at' => now()]);
    legacyLaragearCredential($user, new SoftwareAuthenticator, ['public_key' => 'not-encrypted']);
    legacyLaragearCredential($user, new SoftwareAuthenticator, ['authenticatable_id' => $user->id + 999]);

    runPasskeyMove();

    expect(Passkey::pluck('credential_id')->all())->toBe([$good->credentialId()])
        ->and(DB::table('webauthn_credentials')->count())->toBe(4);
    Log::shouldHaveReceived('warning')->once();
});

test('rolling the migration back drops passkeys and leaves the laragear rows intact', function () {
    $user = User::factory()->create();
    legacyLaragearCredential($user, new SoftwareAuthenticator);
    runPasskeyMove();
    expect(Passkey::count())->toBe(1);

    passkeyMoveMigration()->down();

    expect(Schema::hasTable('passkeys'))->toBeFalse()
        ->and(DB::table('webauthn_credentials')->count())->toBe(1);

    passkeyMoveMigration()->up();
    expect(Passkey::count())->toBe(1);
});

test('rsa and ed25519 public keys convert to equivalent cose keys', function () {
    $migration = passkeyMoveMigration();
    $decode = fn (string $cose) => Decoder::create()->decode(StringStream::create($cose))->normalize();

    $rsa = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $rsaPem = openssl_pkey_get_details($rsa)['key'];
    $rsaCose = $decode($migration->coseKeyFromPem($rsaPem));
    expect((int) $rsaCose[3])->toBe(-257)
        ->and(trim(RsaKey::create($rsaCose)->asPem()))->toBe(trim($rsaPem));

    $ed = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_ED25519]);
    $edPem = openssl_pkey_get_details($ed)['key'];
    $edCose = $decode($migration->coseKeyFromPem($edPem));
    expect((int) $edCose[3])->toBe(-8)
        ->and(trim(OkpKey::create($edCose)->asPEM()))->toBe(trim($edPem));
});
