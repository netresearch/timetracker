<?php

declare(strict_types=1);

namespace Tests\Service\Security;

use App\Entity\User;
use App\Service\Security\TokenEncryptionService;
use App\Service\Security\TwoFactorEnrollmentService;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function rawurldecode;
use function strlen;

/**
 * @internal
 */
#[CoversClass(TwoFactorEnrollmentService::class)]
final class TwoFactorEnrollmentServiceTest extends TestCase
{
    private const string SECRET = 'JBSWY3DPEHPK3PXP';

    private function service(MockClock $clock): TwoFactorEnrollmentService
    {
        $encryption = self::createStub(TokenEncryptionService::class);
        $encryption->method('encryptToken')->willReturnCallback(static fn (string $secret): string => 'ENC(' . $secret . ')');

        return new TwoFactorEnrollmentService($encryption, $clock, 'TimeTracker Test');
    }

    public function testGenerateSecretReturnsBase32(): void
    {
        $secret = $this->service(new MockClock())->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        self::assertGreaterThanOrEqual(16, strlen($secret));
    }

    public function testProvisioningUriCarriesIssuerUsernameAndSecret(): void
    {
        $user = new User()->setUsername('jane');

        $uri = rawurldecode($this->service(new MockClock())->provisioningUri($user, self::SECRET));

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('jane', $uri);
        self::assertStringContainsString('issuer=TimeTracker Test', $uri);
        self::assertStringContainsString('secret=' . self::SECRET, $uri);
    }

    public function testConfirmWithValidCodeStoresEncryptedSecretAndUsableBackupCodes(): void
    {
        $clock = new MockClock('2026-07-03 12:00:00');
        $service = $this->service($clock);
        $code = TOTP::createFromSecret(self::SECRET, $clock)->now();

        $user = new User()->setUsername('jane');
        $backupCodes = $service->confirm($user, self::SECRET, $code);

        self::assertIsArray($backupCodes);
        self::assertCount(8, $backupCodes);
        self::assertSame('ENC(' . self::SECRET . ')', $user->getTotpSecret(), 'the secret is stored ENCRYPTED');
        self::assertTrue($user->isTotpAuthenticationEnabled());
        // Every returned plain code verifies against its stored hash (single-use
        // invalidation is a separate concern, covered in UserTest).
        foreach ($backupCodes as $plain) {
            self::assertTrue($user->isBackupCode($plain));
        }
        self::assertCount(8, $user->getBackupCodes());
    }

    public function testConfirmWithInvalidCodeReturnsNullAndStoresNothing(): void
    {
        $clock = new MockClock('2026-07-03 12:00:00');
        $service = $this->service($clock);
        $valid = TOTP::createFromSecret(self::SECRET, $clock)->now();
        $wrong = '000000' === $valid ? '111111' : '000000';

        $user = new User()->setUsername('jane');
        $result = $service->confirm($user, self::SECRET, $wrong);

        self::assertNull($result);
        self::assertNull($user->getTotpSecret());
        self::assertSame([], $user->getBackupCodes());
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testDisableClearsSecretAndBackupCodes(): void
    {
        $user = new User();
        $user->setTotpSecret('ENC(x)', 'x');
        $user->setBackupCodes(['some-hash']);

        $this->service(new MockClock())->disable($user);

        self::assertNull($user->getTotpSecret());
        self::assertSame([], $user->getBackupCodes());
        self::assertFalse($user->isTotpAuthenticationEnabled());
    }
}
