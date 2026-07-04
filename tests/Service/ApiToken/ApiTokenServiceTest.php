<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Service\ApiToken;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ApiToken\ApiTokenService;
use App\Service\FrozenClock;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenServiceTest extends TestCase
{
    private const string NOW = '2024-01-15 12:00:00';

    public function testCreateReturnsPrefixedPlaintextAndStoresOnlyItsHash(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($entityManager, $this->createMock(ApiTokenRepository::class));
        [$token, $plaintext] = $service->create($this->createMock(User::class), 'ci', ['entries:write', 'entries:read']);

        self::assertStringStartsWith('tt_pat_', $plaintext);
        // Only the hash is persisted, never the plaintext.
        self::assertSame($service->hash($plaintext), $token->getTokenHash());
        self::assertNotSame($plaintext, $token->getTokenHash());
        self::assertSame(['entries:write', 'entries:read'], $token->getScopes());
    }

    public function testCreateRejectsUnknownScope(): void
    {
        $service = $this->service($this->createMock(EntityManagerInterface::class), $this->createMock(ApiTokenRepository::class));

        $this->expectException(InvalidArgumentException::class);
        $service->create($this->createMock(User::class), 'x', ['entries:delete']);
    }

    public function testCreateRejectsEmptyScopes(): void
    {
        $service = $this->service($this->createMock(EntityManagerInterface::class), $this->createMock(ApiTokenRepository::class));

        $this->expectException(InvalidArgumentException::class);
        $service->create($this->createMock(User::class), 'x', []);
    }

    public function testFindActiveRejectsAWrongPrefixWithoutHittingTheRepository(): void
    {
        $apiTokenRepository = $this->createMock(ApiTokenRepository::class);
        $apiTokenRepository->expects(self::never())->method('findOneByHash');

        $service = $this->service($this->createMock(EntityManagerInterface::class), $apiTokenRepository);
        self::assertNull($service->findActiveByPlaintext('nope_1234'));
    }

    public function testFindActiveReturnsAnActiveToken(): void
    {
        $token = $this->token(revoked: false, expired: false);
        $apiTokenRepository = $this->createMock(ApiTokenRepository::class);
        $apiTokenRepository->method('findOneByHash')->willReturn($token);

        $service = $this->service($this->createMock(EntityManagerInterface::class), $apiTokenRepository);
        self::assertSame($token, $service->findActiveByPlaintext('tt_pat_abc'));
    }

    public function testFindActiveRejectsRevokedAndExpired(): void
    {
        $apiTokenRepository = $this->createMock(ApiTokenRepository::class);
        $service = $this->service($this->createMock(EntityManagerInterface::class), $apiTokenRepository);

        $apiTokenRepository->method('findOneByHash')->willReturn($this->token(revoked: true, expired: false));
        self::assertNull($service->findActiveByPlaintext('tt_pat_revoked'));
    }

    public function testFindActiveRejectsExpired(): void
    {
        $apiTokenRepository = $this->createMock(ApiTokenRepository::class);
        $apiTokenRepository->method('findOneByHash')->willReturn($this->token(revoked: false, expired: true));

        $service = $this->service($this->createMock(EntityManagerInterface::class), $apiTokenRepository);
        self::assertNull($service->findActiveByPlaintext('tt_pat_expired'));
    }

    public function testRevokeStampsRevokedAtAndFlushes(): void
    {
        $token = $this->token(revoked: false, expired: false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($entityManager, $this->createMock(ApiTokenRepository::class));
        $service->revoke($token);

        self::assertNotNull($token->getRevokedAt());
    }

    public function testRecordUsageStampsLastUsedAtFromTheClockAndFlushes(): void
    {
        $token = $this->token(revoked: false, expired: false);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = $this->service($entityManager, $this->createMock(ApiTokenRepository::class));
        $service->recordUsage($token);

        self::assertSame(self::NOW, $token->getLastUsedAt()?->format('Y-m-d H:i:s'));
    }

    public function testGrantableScopesExcludesTheScopesAlreadyOnTheToken(): void
    {
        $service = $this->service($this->createMock(EntityManagerInterface::class), $this->createMock(ApiTokenRepository::class));

        $grantable = $service->grantableScopes(['entries:read', '*']);

        self::assertNotContains('entries:read', $grantable);
        self::assertNotContains('*', $grantable);
        self::assertContains('projects:write', $grantable);
    }

    private function service(EntityManagerInterface $entityManager, ApiTokenRepository $apiTokenRepository): ApiTokenService
    {
        return new ApiTokenService($entityManager, $apiTokenRepository, new FrozenClock(self::NOW));
    }

    private function token(bool $revoked, bool $expired): ApiToken
    {
        $now = new DateTimeImmutable(self::NOW);

        return new ApiToken(
            $this->createMock(User::class),
            'name',
            'hash',
            ['entries:read'],
            $now,
            $expired ? $now->modify('-1 day') : $now->modify('+1 day'),
            null,
            $revoked ? $now : null,
        );
    }
}
