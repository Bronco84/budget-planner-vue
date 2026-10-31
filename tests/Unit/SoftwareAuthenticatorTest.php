<?php

use Laragear\WebAuthn\Attestation\AuthenticatorData;
use Laragear\WebAuthn\ByteBuffer;
use Laragear\WebAuthn\CborDecoder;
use Tests\Support\SoftwareAuthenticator;

/*
 * Sanity checks for the test fixture itself, decoded with Laragear's own
 * parsers so a fixture bug can't masquerade as an app bug.
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

test('the cbor encoder round trips through laragears decoder', function () {
    $value = [
        'fmt' => 'none',
        'attStmt' => [],
        1 => 2,
        -1 => 1,
        -25 => 23,
        24 => 255,
        256 => 65536,
        'big' => 4294967296,
        'bytes' => new ByteBuffer(str_repeat("\xAB", 300)),
        'nested' => ['k' => 'v'],
    ];

    $decoded = CborDecoder::decode(SoftwareAuthenticator::cbor($value));

    expect($decoded['fmt'])->toBe('none')
        ->and($decoded['attStmt'])->toBe([])
        ->and($decoded[1])->toBe(2)
        ->and($decoded[-1])->toBe(1)
        ->and($decoded[-25])->toBe(23)
        ->and($decoded[24])->toBe(255)
        ->and($decoded[256])->toBe(65536)
        ->and($decoded['big'])->toBe(4294967296)
        ->and($decoded['bytes'])->toBeInstanceOf(ByteBuffer::class)
        ->and($decoded['bytes']->getBinaryString())->toBe(str_repeat("\xAB", 300))
        ->and($decoded['nested'])->toBe(['k' => 'v']);
});

test('an attestation carries the rp id hash, flags, credential id and the authenticators public key', function () {
    $authenticator = new SoftwareAuthenticator;
    $options = fixtureOptions();

    $payload = $authenticator->attest($options, 'https://example.test');

    $attestation = CborDecoder::decode(ByteBuffer::decodeBase64Url($payload['response']['attestationObject']));
    $authData = AuthenticatorData::fromBinary($attestation['authData']->getBinaryString());
    $clientData = json_decode(ByteBuffer::decodeBase64Url($payload['response']['clientDataJSON']), true);

    expect($attestation['fmt'])->toBe('none')
        ->and($payload['id'])->toBe($authenticator->credentialId())
        ->and($authData->hasSameRPIdHash('example.test'))->toBeTrue()
        ->and($authData->wasUserPresent())->toBeTrue()
        ->and($authData->wasUserVerified())->toBeTrue()
        ->and(SoftwareAuthenticator::b64url($authData->attestedCredentialData->credentialId->getBinaryString()))->toBe($authenticator->credentialId())
        ->and($authData->getPublicKeyPem())->toBe($authenticator->publicKeyPem())
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

    $attestation = CborDecoder::decode(ByteBuffer::decodeBase64Url($payload['response']['attestationObject']));

    expect(AuthenticatorData::fromBinary($attestation['authData']->getBinaryString())->hasSameRPIdHash('fallback.test'))->toBeTrue();
});

test('an assertion is signed with the credential key and increments the counter', function () {
    $authenticator = new SoftwareAuthenticator;
    $creation = fixtureOptions();
    $authenticator->attest($creation, 'https://example.test');

    $first = $authenticator->assert(['challenge' => SoftwareAuthenticator::b64url(random_bytes(16)), 'rpId' => 'example.test'], 'https://example.test');
    $second = $authenticator->assert(['challenge' => SoftwareAuthenticator::b64url(random_bytes(16)), 'rpId' => 'example.test'], 'https://example.test');

    $authData = ByteBuffer::decodeBase64Url($second['response']['authenticatorData']);
    $signed = $authData.hash('sha256', ByteBuffer::decodeBase64Url($second['response']['clientDataJSON']), true);

    expect(openssl_verify($signed, ByteBuffer::decodeBase64Url($second['response']['signature']), $authenticator->publicKeyPem(), OPENSSL_ALGO_SHA256))->toBe(1)
        ->and(AuthenticatorData::fromBinary(ByteBuffer::decodeBase64Url($first['response']['authenticatorData']))->counter)->toBe(1)
        ->and(AuthenticatorData::fromBinary($authData)->counter)->toBe(2)
        ->and(AuthenticatorData::fromBinary($authData)->hasSameRPIdHash('example.test'))->toBeTrue()
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
