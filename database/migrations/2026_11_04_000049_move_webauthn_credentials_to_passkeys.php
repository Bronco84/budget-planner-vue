<?php

use App\Models\User;
use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\UnsignedIntegerObject;
use Cose\Algorithms;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\OkpKey;
use Cose\Key\RsaKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Moves passkeys from laragear/webauthn (abandoned) to laravel/passkeys.
 *
 * Creates the `passkeys` table laravel/passkeys expects and copies every
 * enabled `webauthn_credentials` row into it, so existing passkeys keep
 * signing users in without re-registering:
 *
 * - laragear stored the public key as an encrypted SPKI PEM; laravel/passkeys
 *   (web-auth/webauthn-lib) verifies against a COSE key, so the PEM is
 *   decrypted and re-encoded as COSE (EC2 P-256/ES256, RSA/RS256 or
 *   Ed25519/EdDSA, the only kinds laragear could verify).
 * - The user handle the authenticator returns is the bytes the browser got as
 *   user.id: laragear sent the UUID's 32 hex digits, which PasskeyRegister.vue
 *   base64url-decoded, so the stored handle is that decoding.
 * - The credential id, sign counter, transports, AAGUID, alias (as name) and
 *   timestamps carry over.
 *
 * `webauthn_credentials` is left untouched, so down() only drops `passkeys`.
 * Rows that cannot be converted (disabled, a key that no longer decrypts, an
 * unknown key type) are skipped and logged; those users sign in with a magic
 * link and register a new passkey.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(User::class, 'user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('credential_id')->unique();
            $table->json('credential');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        if (! Schema::hasTable('webauthn_credentials')) {
            return;
        }

        $userTypes = array_values(array_unique([User::class, (new User)->getMorphClass()]));

        DB::table('webauthn_credentials')
            ->whereIn('authenticatable_type', $userTypes)
            ->whereNull('disabled_at')
            ->whereIn('authenticatable_id', DB::table('users')->select('id'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->each(function (object $row): void {
                try {
                    $passkey = $this->convert($row);
                } catch (Throwable $e) {
                    Log::warning('Passkey migration skipped a WebAuthn credential that could not be converted', [
                        'user_id' => $row->authenticatable_id,
                        'reason' => $e->getMessage(),
                    ]);

                    return;
                }

                DB::table('passkeys')->insert($passkey);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }

    /**
     * Map one laragear credential row to a laravel/passkeys row.
     *
     * @return array<string, mixed>
     */
    public function convert(object $row): array
    {
        $rawId = Base64UrlSafe::decodeNoPadding(rtrim($row->id, '='));

        $record = CredentialRecord::create(
            publicKeyCredentialId: $rawId,
            type: 'public-key',
            transports: array_values(array_filter((array) json_decode($row->transports ?? '[]', true), 'is_string')),
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($row->aaguid ?: '00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: $this->coseKeyFromPem(Crypt::decryptString($row->public_key)),
            userHandle: Base64UrlSafe::decodeNoPadding(str_replace('-', '', strtolower($row->user_id))),
            counter: (int) $row->counter,
        );

        return [
            'user_id' => $row->authenticatable_id,
            'name' => $row->alias ?: 'Passkey',
            'credential_id' => Base64UrlSafe::encodeUnpadded($rawId),
            'credential' => WebAuthn::toJson($record),
            'last_used_at' => null,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * Re-encode an SPKI PEM public key as a CBOR COSE_Key.
     */
    public function coseKeyFromPem(string $pem): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $pem), true)
            ?: throw new RuntimeException('Public key is not a PEM.');

        // id-Ed25519 (1.3.101.112): the raw 32-byte key ends the SPKI.
        if (str_contains($der, "\x06\x03\x2B\x65\x70")) {
            return $this->cose([
                Key::TYPE => Key::TYPE_OKP,
                Key::ALG => Algorithms::COSE_ALGORITHM_EDDSA,
                OkpKey::DATA_CURVE => OkpKey::CURVE_ED25519,
                OkpKey::DATA_X => substr($der, -32),
            ]);
        }

        $details = openssl_pkey_get_details(
            openssl_pkey_get_public($pem) ?: throw new RuntimeException('Public key is invalid.')
        ) ?: throw new RuntimeException('Public key is unreadable.');

        if (isset($details['ec'])) {
            ($details['ec']['curve_name'] ?? null) === 'prime256v1'
                || throw new RuntimeException('Unsupported EC curve.');

            return $this->cose([
                Key::TYPE => Key::TYPE_EC2,
                Key::ALG => Algorithms::COSE_ALGORITHM_ES256,
                Ec2Key::DATA_CURVE => Ec2Key::CURVE_P256,
                Ec2Key::DATA_X => str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT),
                Ec2Key::DATA_Y => str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT),
            ]);
        }

        if (isset($details['rsa'])) {
            return $this->cose([
                Key::TYPE => Key::TYPE_RSA,
                Key::ALG => Algorithms::COSE_ALGORITHM_RS256,
                RsaKey::DATA_N => $details['rsa']['n'],
                RsaKey::DATA_E => $details['rsa']['e'],
            ]);
        }

        throw new RuntimeException('Unsupported public key type.');
    }

    /**
     * @param  array<int, int|string>  $map  integer labels to integers or byte strings
     */
    private function cose(array $map): string
    {
        $int = fn (int $value) => $value < 0 ? NegativeIntegerObject::create($value) : UnsignedIntegerObject::create($value);

        return (string) MapObject::create(array_map(
            fn (int $label, int|string $value) => MapItem::create(
                $int($label),
                is_int($value) ? $int($value) : ByteStringObject::create($value),
            ),
            array_keys($map),
            $map,
        ));
    }
};
