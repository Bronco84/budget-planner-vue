<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * An in-process WebAuthn authenticator for tests.
 *
 * It holds a real EC P-256 key pair (ES256, COSE alg -7) and produces the same
 * payloads a browser + platform authenticator would hand to the app's
 * PasskeyRegister.vue / PasskeyLogin.vue (base64url fields), so the real
 * laravel/passkeys (web-auth/webauthn-lib) attestation and assertion
 * validation runs against it unmodified.
 *
 * Like a browser, it takes the RP ID from the ceremony options (rp.id / rpId)
 * and falls back to the origin's host when the server omits it.
 */
final class SoftwareAuthenticator
{
    private const FLAG_USER_PRESENT = 0x01;

    private const FLAG_USER_VERIFIED = 0x04;

    private const FLAG_ATTESTED_DATA = 0x40;

    private const COSE_KTY_EC2 = 2;

    private const COSE_ALG_ES256 = -7;

    private const COSE_CRV_P256 = 1;

    private OpenSSLAsymmetricKey $privateKey;

    private string $rawCredentialId;

    private int $counter = 0;

    /** Raw user.id bytes from the creation options, returned as userHandle on assertion. */
    private ?string $userHandle = null;

    /**
     * @param  string|null  $rawCredentialId  raw credential id bytes (random when omitted)
     * @param  bool  $counts  false mimics synced passkeys that always report a zero signature counter
     */
    public function __construct(?string $rawCredentialId = null, private bool $counts = true)
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('Unable to generate an EC P-256 key: '.openssl_error_string());
        }

        $this->privateKey = $key;
        $this->rawCredentialId = $rawCredentialId ?? random_bytes(32);
    }

    /**
     * The credential id as the browser exposes it (PublicKeyCredential.id, base64url).
     */
    public function credentialId(): string
    {
        return self::b64url($this->rawCredentialId);
    }

    public function counter(): int
    {
        return $this->counter;
    }

    /**
     * The public key as an SPKI PEM (the form laragear/webauthn stored).
     */
    public function publicKeyPem(): string
    {
        return openssl_pkey_get_details($this->privateKey)['key'];
    }

    /**
     * navigator.credentials.create(): returns the payload POSTed to webauthn.register.
     *
     * @param  array<string, mixed>  $creationOptions  JSON from webauthn.register.options
     * @return array<string, mixed>
     */
    public function attest(array $creationOptions, string $origin): array
    {
        foreach ($creationOptions['excludeCredentials'] ?? [] as $excluded) {
            if (($excluded['id'] ?? null) === $this->credentialId()) {
                throw new RuntimeException('InvalidStateError: this authenticator already holds a credential for the user.');
            }
        }

        $rpId = $creationOptions['rp']['id'] ?? self::hostOf($origin);
        $this->userHandle = isset($creationOptions['user']['id'])
            ? self::b64urlDecode($creationOptions['user']['id'])
            : null;

        $clientDataJson = self::clientDataJson('webauthn.create', $creationOptions['challenge'], $origin);

        $authenticatorData = hash('sha256', $rpId, true)
            .chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | self::FLAG_ATTESTED_DATA)
            .pack('N', $this->counter)
            .str_repeat("\0", 16) // AAGUID: "none" attestation software authenticator
            .pack('n', strlen($this->rawCredentialId))
            .$this->rawCredentialId
            .self::cbor($this->cosePublicKey());

        $attestationObject = self::cbor([
            'fmt' => 'none',
            'attStmt' => [],
            'authData' => ByteStringObject::create($authenticatorData),
        ]);

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::b64url($clientDataJson),
                'attestationObject' => self::b64url($attestationObject),
            ],
        ];
    }

    /**
     * navigator.credentials.get(): returns the payload POSTed to webauthn.login.
     *
     * @param  array<string, mixed>  $requestOptions  JSON from webauthn.login.options
     * @return array<string, mixed>
     */
    public function assert(array $requestOptions, string $origin): array
    {
        $allowed = array_column($requestOptions['allowCredentials'] ?? [], 'id');

        if ($allowed !== [] && ! in_array($this->credentialId(), $allowed, true)) {
            throw new RuntimeException('NotAllowedError: no allowed credential is held by this authenticator.');
        }

        $rpId = $requestOptions['rpId'] ?? self::hostOf($origin);
        $clientDataJson = self::clientDataJson('webauthn.get', $requestOptions['challenge'], $origin);

        $authenticatorData = hash('sha256', $rpId, true)
            .chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED)
            .pack('N', $this->counts ? ++$this->counter : 0);

        // WebAuthn ES256 signatures are ASN.1 DER ECDSA over authData || SHA-256(clientDataJSON).
        if (! openssl_sign($authenticatorData.hash('sha256', $clientDataJson, true), $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Unable to sign the assertion: '.openssl_error_string());
        }

        return [
            'id' => $this->credentialId(),
            'rawId' => $this->credentialId(),
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => self::b64url($authenticatorData),
                'clientDataJSON' => self::b64url($clientDataJson),
                'signature' => self::b64url($signature),
                'userHandle' => $this->userHandle === null ? null : self::b64url($this->userHandle),
            ],
        ];
    }

    /**
     * COSE_Key for the EC2 P-256 public key.
     *
     * @return array<int, int|ByteStringObject>
     */
    private function cosePublicKey(): array
    {
        $ec = openssl_pkey_get_details($this->privateKey)['ec'];

        return [
            1 => self::COSE_KTY_EC2,
            3 => self::COSE_ALG_ES256,
            -1 => self::COSE_CRV_P256,
            -2 => ByteStringObject::create(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            -3 => ByteStringObject::create(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    private static function clientDataJson(string $type, string $challenge, string $origin): string
    {
        return json_encode([
            'type' => $type,
            // The browser re-encodes the challenge bytes it was given as base64url.
            'challenge' => self::b64url(self::b64urlDecode($challenge)),
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Minimal CBOR (RFC 8949) encoder: unsigned/negative ints, text strings,
     * byte strings (ByteStringObject) and maps (PHP arrays with int or text keys).
     */
    public static function cbor(mixed $value): string
    {
        return match (true) {
            is_int($value) && $value >= 0 => self::cborHead(0, $value),
            is_int($value) => self::cborHead(1, -1 - $value),
            $value instanceof ByteStringObject => self::cborHead(2, strlen($value->getValue())).$value->getValue(),
            is_string($value) => self::cborHead(3, strlen($value)).$value,
            is_array($value) => self::cborHead(5, count($value)).implode('', array_map(
                static fn ($key, $item): string => self::cbor($key).self::cbor($item),
                array_keys($value),
                $value,
            )),
            default => throw new InvalidArgumentException('Unsupported CBOR value of type '.get_debug_type($value)),
        };
    }

    private static function cborHead(int $major, int $argument): string
    {
        $type = $major << 5;

        return match (true) {
            $argument < 24 => chr($type | $argument),
            $argument <= 0xFF => chr($type | 24).chr($argument),
            $argument <= 0xFFFF => chr($type | 25).pack('n', $argument),
            $argument <= 0xFFFFFFFF => chr($type | 26).pack('N', $argument),
            default => chr($type | 27).pack('J', $argument),
        };
    }

    private static function hostOf(string $origin): string
    {
        return parse_url($origin, PHP_URL_HOST)
            ?: throw new InvalidArgumentException("Origin [$origin] has no host.");
    }

    public static function b64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $encoded): string
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64url string.');
        }

        return $decoded;
    }
}
