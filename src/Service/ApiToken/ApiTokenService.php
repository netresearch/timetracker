<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\ApiToken;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Service\ClockInterface;
use App\ValueObject\ApiScope;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use SensitiveParameter;

use function hash;
use function in_array;
use function sprintf;
use function str_starts_with;

/**
 * Mints, verifies, and revokes API tokens (ADR-021). Tokens are opaque with a
 * `tt_pat_` prefix; only their SHA-256 hash is stored. This service is auth-free
 * (no firewall) — the Bearer authenticator that consumes findActiveByPlaintext()
 * arrives in Phase 2.
 */
final readonly class ApiTokenService
{
    /** Recognizable prefix for secret-scanning and safe logging. */
    public const string PREFIX = 'tt_pat_';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ApiTokenRepository $apiTokenRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Create a token and return the entity plus the ONE-TIME plaintext (never
     * recoverable afterwards).
     *
     * @param list<string> $scopes
     *
     * @throws InvalidArgumentException on an unknown scope or an empty scope list
     *
     * @return array{0: ApiToken, 1: string}
     */
    public function create(User $user, string $name, array $scopes, ?DateTimeImmutable $expiresAt = null): array
    {
        if ([] === $scopes) {
            throw new InvalidArgumentException('At least one scope is required.');
        }

        foreach ($scopes as $scope) {
            if (!ApiScope::isValid($scope)) {
                throw new InvalidArgumentException(sprintf('Unknown scope: %s', $scope));
            }
        }

        $plaintext = self::PREFIX . bin2hex(random_bytes(32));
        $token = new ApiToken(
            $user,
            $name,
            $this->hash($plaintext),
            array_values(array_unique($scopes)),
            $this->clock->now(),
            $expiresAt,
        );

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return [$token, $plaintext];
    }

    public function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Resolve a presented plaintext token to its active entity, or null if the
     * prefix is wrong, the token is unknown, revoked, or expired.
     */
    public function findActiveByPlaintext(#[SensitiveParameter] string $plaintext): ?ApiToken
    {
        if (!str_starts_with($plaintext, self::PREFIX)) {
            return null;
        }

        $token = $this->apiTokenRepository->findOneByHash($this->hash($plaintext));
        if (!$token instanceof ApiToken || !$token->isActive($this->clock->now())) {
            return null;
        }

        return $token;
    }

    public function recordUsage(ApiToken $token): void
    {
        $token->setLastUsedAt($this->clock->now());
        $this->entityManager->flush();
    }

    public function revoke(ApiToken $token): void
    {
        $token->revoke($this->clock->now());
        $this->entityManager->flush();
    }

    /**
     * The scopes an owner may still grant that are not already on the token
     * (used by the future management UI); a thin wrapper over the taxonomy so
     * callers don't import ApiScope directly.
     *
     * @param list<string> $current
     *
     * @return list<string>
     */
    public function grantableScopes(array $current): array
    {
        return array_values(array_filter(ApiScope::all(), static fn (string $scope): bool => !in_array($scope, $current, true)));
    }
}
