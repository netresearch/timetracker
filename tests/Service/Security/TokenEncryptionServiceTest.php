<?php

declare(strict_types=1);

namespace Tests\Service\Security;

use App\Service\Security\TokenEncryptionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Unit tests for TokenEncryptionService.
 *
 * @internal
 */
#[CoversClass(TokenEncryptionService::class)]
final class TokenEncryptionServiceTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithValidKey(): void
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnMap([
                ['app.encryption_key', 'my-secret-key-123'],
            ]);

        $service = new TokenEncryptionService($parameterBag);

        // Verify service works by encrypting a token
        $encrypted = $service->encryptToken('test');
        self::assertNotSame('test', $encrypted);
    }

    public function testConstructorFallsBackToAppSecret(): void
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnMap([
                ['app.encryption_key', null],
                ['APP_SECRET', 'fallback-app-secret'],
            ]);

        $service = new TokenEncryptionService($parameterBag);

        // Verify service works by encrypting a token
        $encrypted = $service->encryptToken('test');
        self::assertNotSame('test', $encrypted);
    }

    public function testConstructorThrowsOnEmptyKey(): void
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnMap([
                ['app.encryption_key', null],
                ['APP_SECRET', ''],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Encryption key not configured');

        new TokenEncryptionService($parameterBag);
    }

    public function testConstructorThrowsOnNonStringKey(): void
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnMap([
                ['app.encryption_key', null],
                ['APP_SECRET', null],
            ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Encryption key not configured');

        new TokenEncryptionService($parameterBag);
    }

    // ==================== encryptToken tests ====================

    public function testEncryptTokenReturnsEmptyForEmptyInput(): void
    {
        $service = $this->createService();

        self::assertSame('', $service->encryptToken(''));
    }

    public function testEncryptTokenReturnsEmptyForZeroString(): void
    {
        $service = $this->createService();

        self::assertSame('', $service->encryptToken('0'));
    }

    public function testEncryptTokenReturnsBase64EncodedString(): void
    {
        $service = $this->createService();

        $encrypted = $service->encryptToken('my-secret-token');

        // Result should be base64 encoded (decodable)
        self::assertNotFalse(base64_decode($encrypted, true));
        // Result should not equal input
        self::assertNotSame('my-secret-token', $encrypted);
    }

    public function testEncryptTokenProducesDifferentOutputEachTime(): void
    {
        $service = $this->createService();
        $token = 'same-token-value';

        $encrypted1 = $service->encryptToken($token);
        $encrypted2 = $service->encryptToken($token);

        // Each encryption should produce different ciphertext (different IV)
        self::assertNotSame($encrypted1, $encrypted2);
    }

    // ==================== decryptToken tests ====================

    public function testDecryptTokenReturnsEmptyForEmptyInput(): void
    {
        $service = $this->createService();

        self::assertSame('', $service->decryptToken(''));
    }

    public function testDecryptTokenReturnsEmptyForZeroString(): void
    {
        $service = $this->createService();

        self::assertSame('', $service->decryptToken('0'));
    }

    public function testDecryptTokenThrowsOnInvalidBase64(): void
    {
        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid encrypted token format');

        // Invalid base64 characters
        $service->decryptToken('not-valid-base64!!!');
    }

    public function testDecryptTokenThrowsOnTokenTooShort(): void
    {
        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Encrypted token too short');

        // Valid base64 but decoded content is too short
        $service->decryptToken(base64_encode('short'));
    }

    public function testDecryptTokenThrowsOnCorruptedToken(): void
    {
        $service = $this->createService();

        // Create a properly sized but corrupted token
        // AES-256-GCM IV is 12 bytes, tag is 16 bytes
        // So we need at least 28 bytes + some encrypted data
        $corruptedData = str_repeat('x', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token decryption failed');

        $service->decryptToken(base64_encode($corruptedData));
    }

    // ==================== encrypt/decrypt roundtrip tests ====================

    public function testEncryptDecryptRoundtrip(): void
    {
        $service = $this->createService();
        $originalToken = 'my-oauth-access-token-12345';

        $encrypted = $service->encryptToken($originalToken);
        $decrypted = $service->decryptToken($encrypted);

        self::assertSame($originalToken, $decrypted);
    }

    public function testEncryptDecryptRoundtripWithSpecialCharacters(): void
    {
        $service = $this->createService();
        $originalToken = 'token/with+special=chars&more%stuff';

        $encrypted = $service->encryptToken($originalToken);
        $decrypted = $service->decryptToken($encrypted);

        self::assertSame($originalToken, $decrypted);
    }

    public function testEncryptDecryptRoundtripWithUnicode(): void
    {
        $service = $this->createService();
        $originalToken = 'token-with-unicode-äöü-中文-🔑';

        $encrypted = $service->encryptToken($originalToken);
        $decrypted = $service->decryptToken($encrypted);

        self::assertSame($originalToken, $decrypted);
    }

    public function testEncryptDecryptRoundtripWithLongToken(): void
    {
        $service = $this->createService();
        $originalToken = str_repeat('a', 10000);

        $encrypted = $service->encryptToken($originalToken);
        $decrypted = $service->decryptToken($encrypted);

        self::assertSame($originalToken, $decrypted);
    }

    // ==================== rotateToken tests ====================

    public function testRotateTokenProducesNewEncryption(): void
    {
        $service = $this->createService();
        $originalToken = 'token-to-rotate';

        $encrypted = $service->encryptToken($originalToken);
        $rotated = $service->rotateToken($encrypted);

        // Rotated token should be different (new IV)
        self::assertNotSame($encrypted, $rotated);

        // But should decrypt to same value
        $decrypted = $service->decryptToken($rotated);
        self::assertSame($originalToken, $decrypted);
    }

    public function testRotateTokenPreservesOriginalValue(): void
    {
        $service = $this->createService();
        $originalToken = 'preserve-this-value';

        $encrypted = $service->encryptToken($originalToken);
        $rotated = $service->rotateToken($encrypted);

        // Multiple rotations should preserve value
        $rotated2 = $service->rotateToken($rotated);
        $rotated3 = $service->rotateToken($rotated2);

        $finalDecrypted = $service->decryptToken($rotated3);
        self::assertSame($originalToken, $finalDecrypted);
    }

    // ==================== Cross-instance tests ====================

    public function testSameKeyCanDecryptAcrossInstances(): void
    {
        $service1 = $this->createService();
        $service2 = $this->createService();

        $originalToken = 'cross-instance-test';
        $encrypted = $service1->encryptToken($originalToken);

        // Different instance with same key should decrypt
        $decrypted = $service2->decryptToken($encrypted);
        self::assertSame($originalToken, $decrypted);
    }

    public function testDifferentKeyCannotDecrypt(): void
    {
        $service1 = $this->createService('key-one');
        $service2 = $this->createService('key-two');

        $originalToken = 'secret-data';
        $encrypted = $service1->encryptToken($originalToken);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Token decryption failed');

        // Different key should fail to decrypt
        $service2->decryptToken($encrypted);
    }

    // ==================== Helper methods ====================

    private function createService(string $key = 'test-encryption-key'): TokenEncryptionService
    {
        $parameterBag = $this->createMock(ParameterBagInterface::class);
        $parameterBag->method('get')
            ->willReturnMap([
                ['app.encryption_key', $key],
            ]);

        return new TokenEncryptionService($parameterBag);
    }
}
