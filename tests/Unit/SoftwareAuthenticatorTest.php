<?php

use CBOR\ByteStringObject;
use CBOR\Decoder;
use CBOR\StringStream;
use Cose\Key\Ec2Key;
use Tests\Support\SoftwareAuthenticator;

/*
 * Sanity checks for the test fixture itself, decoded with the CBOR / COSE
 * libraries web-auth/webauthn-lib uses, so a fixture bug can't masquerade as
 * an app bug.
 */

function fixtureOptions(array $extra = []): array
{
    return [
        'challenge' => SoftwareAuthenticator::b64url(random_bytes(16)),
        'rp' => ['name' => 'Test', 'id' => 'example.test'],
        'user' => ['id' => bin2hex(random_bytes(16)), 'name' => 'a@example.test'],
        ...$extra,
    ];
}

function cborDecode(string $binary): mixed
{
    return Decoder::create()->decode(StringStream::create($binary))->normalize();
}

/**
 * Split WebAuthn authenticator data (rpIdHash | flags | signCount [| attested credential]).
 *
 * @return array{rpIdHash: string, flags: int, counter: int, credentialId: ?string, publicKey: ?array}
 */
function parseAuthenticatorData(string $authData): array
{
    $parsed = [
        'rpIdHash' => substr($authData, 0, 32),
        'flags' => ord($authData[32]),
        'counter' => unpack('N', substr($authData, 33, 4))[1],
        'credentialId' => null,
        'publicKey' => null,
    ];

    if ($parsed['flags'] & 0x40) {
        $length = unpack('n', substr($authData, 53, 2))[1];
        $parsed['credentialId'] = substr($authData, 55, $length);
        $parsed['publicKey'] = cborDecode(substr($authData, 55 + $length));
    }

    return $parsed;
}

test('the cbor encoder round trips through the cbor decoder', function () {
    $value = [
        'fmt' => 'none',
        'attStmt' => [],
        1 => 2,
        -1 => 1,
        -25 => 23,
        24 => 255,
        256 => 65536,
        'big' => 4294967296,
        'bytes' => ByteStringObject::create(str_repeat("\xAB", 300)),
        'nested' => ['k' => 'v'],
    ];

    $decoded = cborDecode(SoftwareAuthenticator::cbor($value));

    expect($decoded['fmt'])->toBe('none')
        ->and($decoded['attStmt'])->toBe([])
        ->and((int) $decoded[1])->toBe(2)
        ->and((int) $decoded[-1])->toBe(1)
        ->and((int) $decoded[-25])->toBe(23)
        ->and((int) $decoded[24])->toBe(255)
        ->and((int) $decoded[256])->toBe(65536)
        ->and((int) $decoded['big'])->toBe(4294967296)
        ->and($decoded['bytes'])->toBe(str_repeat("\xAB", 300))
        ->and($decoded['nested'])->toBe(['k' => 'v']);
});

test('an attestation carries the rp id hash, flags, credential id and the authenticators public key', function () {
    $authenticator = new SoftwareAuthenticator;
    $options = fixtureOptions();

    $payload = $authenticator->attest($options, 'https://example.test');

    $attestation = cborDecode(SoftwareAuthenticator::b64urlDecode($payload['response']['attestationObject']));
    $authData = parseAuthenticatorData($attestation['authData']);
    $clientData = json_decode(SoftwareAuthenticator::b64urlDecode($payload['response']['clientDataJSON']), true);

    expect($attestation['fmt'])->toBe('none')
        ->and($payload['id'])->toBe($authenticator->credentialId())
        ->and($authData['rpIdHash'])->toBe(hash('sha256', 'example.test', true))
        ->and($authData['flags'] & 0x01)->toBe(0x01) // user present
        ->and($authData['flags'] & 0x04)->toBe(0x04) // user verified
        ->and(SoftwareAuthenticator::b64url($authData['credentialId']))->toBe($authenticator->credentialId())
        ->and(Ec2Key::create($authData['publicKey'])->asPEM())->toBe($authenticator->publicKeyPem())
        ->and($clientData)->toMatchArray([
            'type' => 'webauthn.create',
            'challenge' => $options['challenge'],
            'origin' => 'https://example.test',
        ]);
});

test('the rp id falls back to the origin host when the options omit it', function () {
    $options = fixtureOptions();
    unset($options['rp']['id']);

    $payload = (new SoftwareAuthenticator)->attest($options, 'https://fallback.test');

    $attestation = cborDecode(SoftwareAuthenticator::b64urlDecode($payload['response']['attestationObject']));

    expect(parseAuthenticatorData($attestation['authData'])['rpIdHash'])->toBe(hash('sha256', 'fallback.test', true));
});

test('an assertion is signed with the credential key and increments the counter', function () {
    $authenticator = new SoftwareAuthenticator;
    $creation = fixtureOptions();
    $authenticator->attest($creation, 'https://example.test');

    $first = $authenticator->assert(['challenge' => SoftwareAuthenticator::b64url(random_bytes(16)), 'rpId' => 'example.test'], 'https://example.test');
    $second = $authenticator->assert(['challenge' => SoftwareAuthenticator::b64url(random_bytes(16)), 'rpId' => 'example.test'], 'https://example.test');

    $authData = SoftwareAuthenticator::b64urlDecode($second['response']['authenticatorData']);
    $signed = $authData.hash('sha256', SoftwareAuthenticator::b64urlDecode($second['response']['clientDataJSON']), true);

    expect(openssl_verify($signed, SoftwareAuthenticator::b64urlDecode($second['response']['signature']), $authenticator->publicKeyPem(), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(parseAuthenticatorData(SoftwareAuthenticator::b64urlDecode($first['response']['authenticatorData']))['counter'])->toBe(1)
        ->and(parseAuthenticatorData($authData)['counter'])->toBe(2)
        ->and(parseAuthenticatorData($authData)['rpIdHash'])->toBe(hash('sha256', 'example.test', true))
        ->and($second['response']['userHandle'])->toBe($creation['user']['id']);
});

test('the authenticator refuses credentials it was told to exclude or not allowed to use', function () {
    $authenticator = new SoftwareAuthenticator;

    expect(fn () => $authenticator->attest(fixtureOptions([
        'excludeCredentials' => [['id' => $authenticator->credentialId(), 'type' => 'public-key']],
    ]), 'https://example.test'))->toThrow(RuntimeException::class, 'InvalidStateError')
        ->and(fn () => $authenticator->assert([
            'challenge' => SoftwareAuthenticator::b64url(random_bytes(16)),
            'allowCredentials' => [['id' => 'someone-else', 'type' => 'public-key']],
        ], 'https://example.test'))->toThrow(RuntimeException::class, 'NotAllowedError');
});
