<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use Iterator;
use OpenSSLAsymmetricKey;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function assert;
use function base64_encode;
use function chr;
use function hash;
use function is_array;
use function is_string;
use function json_encode;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function pack;
use function rtrim;
use function str_pad;
use function strlen;
use function strtr;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const STR_PAD_LEFT;

/**
 * One RP ID, several origins (WebAuthn Related Origin Requests): a software
 * authenticator runs complete registration and login ceremonies over HTTP, and
 * /.well-known/webauthn publishes the configured origins.
 *
 * @internal
 *
 * @coversNothing
 */
final class PasskeyRelatedOriginsTest extends AbstractWebTestCase
{
    private const string RP_ID = 'tt.example.com';

    private const string CREDENTIAL_ID = 'related-origins-credential-id-01';

    private const string BOTH = ' https://tt.example.com , ,https://tt.example.org,';

    /** @var array<mixed> */
    private array $envBackup = [];

    #[Override]
    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $_ENV['WEBAUTHN_RP_ID'] = self::RP_ID;
        parent::setUp();
    }

    #[Override]
    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        parent::tearDown();
    }

    #[DataProvider('acceptedOriginProvider')]
    public function testAcceptsListedOriginsForRegistrationAndLogin(string $allowed, string $registrationOrigin, string $loginOrigin): void
    {
        $_ENV['WEBAUTHN_ALLOWED_ORIGINS'] = $allowed;
        $key = $this->newKey();

        $userHandle = $this->register($key, $registrationOrigin);
        $this->assertStatusCode(201);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['success'] ?? null);

        $this->login($key, $loginOrigin, $userHandle);
        $this->assertStatusCode(200);
        self::assertTrue($this->getJsonResponse($this->client->getResponse())['ok'] ?? null);
    }

    /**
     * @return Iterator<string, array{string, string, string}>
     */
    public static function acceptedOriginProvider(): Iterator
    {
        yield 'first of two' => [self::BOTH, 'https://tt.example.com', 'https://tt.example.com'];
        yield 'second of two' => [self::BOTH, 'https://tt.example.org', 'https://tt.example.org'];
        yield 'registered on the first, used on the second' => [self::BOTH, 'https://tt.example.com', 'https://tt.example.org'];
        yield 'registered on the second, used on the first' => [self::BOTH, 'https://tt.example.org', 'https://tt.example.com'];
        yield 'single value' => ['https://tt.example.com', 'https://tt.example.com', 'https://tt.example.com'];
    }

    #[DataProvider('rejectedOriginProvider')]
    public function testRejectsAnUnlistedOriginForRegistration(string $allowed, string $origin): void
    {
        $_ENV['WEBAUTHN_ALLOWED_ORIGINS'] = $allowed;

        $this->register($this->newKey(), $origin);

        $this->assertStatusCode(422);
        self::assertFalse($this->getJsonResponse($this->client->getResponse())['success'] ?? null);
    }

    public function testRejectsTheRpIdHostWhenTheVariableIsEmpty(): void
    {
        $_ENV['WEBAUTHN_ALLOWED_ORIGINS'] = '';

        $this->register($this->newKey(), 'https://tt.example.com');

        $this->assertStatusCode(422);
    }

    #[DataProvider('rejectedOriginProvider')]
    public function testRejectsAnUnlistedOriginForLogin(string $allowed, string $origin): void
    {
        // One list for both ceremonies: the test client never reboots the kernel,
        // so the first ceremony fixes the list.
        $_ENV['WEBAUTHN_ALLOWED_ORIGINS'] = $allowed;
        $key = $this->newKey();
        $userHandle = $this->register($key, 'https://tt.example.com');
        $this->assertStatusCode(201);

        $this->login($key, $origin, $userHandle);

        $this->assertStatusCode(401);
        self::assertFalse($this->getJsonResponse($this->client->getResponse())['ok'] ?? null);
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function rejectedOriginProvider(): Iterator
    {
        yield 'unlisted' => [self::BOTH, 'https://tt.example.net'];
        yield 'subdomain of a listed origin' => [self::BOTH, 'https://sub.tt.example.org'];
        yield 'other port' => [self::BOTH, 'https://tt.example.org:8443'];
        yield 'plain http' => [self::BOTH, 'http://tt.example.org'];
        yield 'wildcard entry' => ['https://tt.example.com,https://*.example.org', 'https://tt.example.org'];
        yield 'second origin with a single value' => ['https://tt.example.com', 'https://tt.example.org'];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('wellKnownProvider')]
    public function testWellKnownListsTheOriginsWithoutLogin(string $allowed, array $expected): void
    {
        $_ENV['WEBAUTHN_ALLOWED_ORIGINS'] = $allowed;
        $this->client->getCookieJar()->clear();

        $this->client->request(Request::METHOD_GET, '/.well-known/webauthn');

        $this->assertStatusCode(200);
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame(
            json_encode(['origins' => $expected], JSON_THROW_ON_ERROR),
            $this->client->getResponse()->getContent(),
        );
    }

    /**
     * @return Iterator<string, array{string, list<string>}>
     */
    public static function wellKnownProvider(): Iterator
    {
        yield 'two origins' => [self::BOTH, ['https://tt.example.com', 'https://tt.example.org']];
        yield 'single value' => ['https://tt.example.com', ['https://tt.example.com']];
    }

    private function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        assert($key instanceof OpenSSLAsymmetricKey);

        return $key;
    }

    /** Runs the registration ceremony and returns the base64url user handle. */
    private function register(OpenSSLAsymmetricKey $key, string $origin): string
    {
        $options = $this->post('/settings/security/passkeys/options', []);
        self::assertIsArray($options['user'] ?? null);
        self::assertIsString($options['user']['id'] ?? null);

        $details = openssl_pkey_get_details($key);
        assert(is_array($details) && is_array($details['ec']) && is_string($details['ec']['x']) && is_string($details['ec']['y']));
        $publicKey = MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)));
        // Flags: user present, user verified, attested credential data; zero AAGUID.
        $authData = $this->authData(0x45, 0)
            . str_pad('', 16, "\0") . pack('n', strlen(self::CREDENTIAL_ID)) . self::CREDENTIAL_ID . $publicKey;
        $attestation = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        $this->post('/settings/security/passkeys', $this->credential([
            'clientDataJSON' => $this->base64Url($this->clientData('webauthn.create', $options['challenge'] ?? null, $origin)),
            'attestationObject' => $this->base64Url((string) $attestation),
            'transports' => [],
        ]));

        return $options['user']['id'];
    }

    private function login(OpenSSLAsymmetricKey $key, string $origin, string $userHandle): void
    {
        $this->client->getCookieJar()->clear();
        $options = $this->post('/login/options', []);

        // Flags: user present, user verified.
        $authData = $this->authData(0x05, 1);
        $clientData = $this->clientData('webauthn.get', $options['challenge'] ?? null, $origin);
        $signature = '';
        self::assertTrue(openssl_sign($authData . hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256));
        assert(is_string($signature));

        $this->post('/login/passkey', $this->credential([
            'clientDataJSON' => $this->base64Url($clientData),
            'authenticatorData' => $this->base64Url($authData),
            'signature' => $this->base64Url($signature),
            'userHandle' => $userHandle,
        ]));
    }

    /** @param int<0, 255> $flags */
    private function authData(int $flags, int $counter): string
    {
        return hash('sha256', self::RP_ID, true) . chr($flags) . pack('N', $counter);
    }

    private function clientData(string $type, mixed $challenge, string $origin): string
    {
        self::assertIsString($challenge);

        return json_encode(
            ['type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    private function credential(array $response): array
    {
        return [
            'id' => $this->base64Url(self::CREDENTIAL_ID),
            'rawId' => $this->base64Url(self::CREDENTIAL_ID),
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => [],
        ];
    }

    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->client->request(
            Request::METHOD_POST,
            $path,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            [] === $body ? '{}' : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this->getJsonResponse($this->client->getResponse());
    }
}
