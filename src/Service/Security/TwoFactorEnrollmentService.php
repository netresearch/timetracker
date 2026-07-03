<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User;
use InvalidArgumentException;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function array_map;
use function bin2hex;
use function password_hash;
use function random_bytes;

use const PASSWORD_DEFAULT;

/**
 * TOTP enrolment (ADR-018 D2): generate a shared secret, build the QR/otpauth
 * provisioning URI, verify a first code, and persist the ENCRYPTED secret plus
 * a set of HASHED one-time backup codes on the user.
 *
 * The secret is never persisted until the user proves possession of the device
 * by entering a valid code (confirm()). All storage goes through the User entity
 * (encrypted secret, hashed codes); the plaintext secret and plain backup codes
 * exist only for the duration of the enrolment request.
 */
final readonly class TwoFactorEnrollmentService
{
    /** Number of one-time recovery codes issued on enrolment. */
    private const int BACKUP_CODE_COUNT = 8;

    /** Accepted clock skew: ±1 period (30 s) each side. */
    private const int VERIFY_LEEWAY = 1;

    public function __construct(
        private TokenEncryptionService $tokenEncryptionService,
        private ClockInterface $clock,
        #[Autowire('%app_title%')]
        private string $issuer,
    ) {
    }

    /** A fresh base32 TOTP secret, not yet stored anywhere. */
    public function generateSecret(): string
    {
        return TOTP::generate($this->clock)->getSecret();
    }

    /** The otpauth:// URI for a QR code, labelled with the username and app issuer. */
    public function provisioningUri(User $user, #[SensitiveParameter] string $secret): string
    {
        $label = (string) $user->getUsername();
        $issuer = '' !== $this->issuer ? $this->issuer : 'TimeTracker';

        return $this->totp($secret)
            ->withLabel('' !== $label ? $label : 'user')
            ->withIssuer($issuer)
            ->getProvisioningUri();
    }

    public function verifyCode(#[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): bool
    {
        if ('' === $secret || '' === $code) {
            return false;
        }

        return $this->totp($secret)->verify($code, null, self::VERIFY_LEEWAY);
    }

    /**
     * Confirm enrolment: verify the code against the pending secret, then store the
     * encrypted secret and hashed backup codes on the user (the caller flushes).
     *
     * @return list<string>|null the plain backup codes to show ONCE, or null if the code is invalid
     */
    public function confirm(User $user, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $code): ?array
    {
        if (!$this->verifyCode($secret, $code)) {
            return null;
        }

        $user->setTotpSecret($this->tokenEncryptionService->encryptToken($secret), $secret);

        $plainCodes = $this->generateBackupCodes();
        $user->setBackupCodes(array_map(
            static fn (string $code): string => password_hash($code, PASSWORD_DEFAULT),
            $plainCodes,
        ));

        return $plainCodes;
    }

    /** Turn 2FA off: drop the secret and any outstanding backup codes. */
    public function disable(User $user): void
    {
        $user->setTotpSecret(null, null);
        $user->setBackupCodes([]);
    }

    /** Build a TOTP for a non-empty secret (otphp requires a non-empty string). */
    private function totp(#[SensitiveParameter] string $secret): TOTP
    {
        if ('' === $secret) {
            throw new InvalidArgumentException('A TOTP secret is required.');
        }

        return TOTP::createFromSecret($secret, $this->clock);
    }

    /**
     * @return list<string>
     */
    private function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::BACKUP_CODE_COUNT; ++$i) {
            // 10 hex chars (~40 bits) — enough entropy for a one-time, rate-limited code.
            $codes[] = bin2hex(random_bytes(5));
        }

        return $codes;
    }
}
