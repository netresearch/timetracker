<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user-bound Personal Access Token (ADR-021). Only the SHA-256 hash of the
 * token is stored; the plaintext (prefix `tt_pat_…`) is shown once at creation.
 * Scopes narrow the owning user's access. A token is active while it is neither
 * revoked nor expired.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
#[ORM\Table(name: 'api_tokens')]
class ApiToken
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private readonly User $user,
        #[ORM\Column(type: 'string', length: 100)]
        private string $name,
        #[ORM\Column(name: 'token_hash', type: 'string', length: 64, unique: true)]
        private readonly string $tokenHash,
        #[ORM\Column(type: 'json')]
        private array $scopes,
        #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
        private readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'expires_at', type: 'datetime_immutable', nullable: true)]
        private readonly ?DateTimeImmutable $expiresAt = null,
        #[ORM\Column(name: 'last_used_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $lastUsedAt = null,
        #[ORM\Column(name: 'revoked_at', type: 'datetime_immutable', nullable: true)]
        private ?DateTimeImmutable $revokedAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getLastUsedAt(): ?DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function setLastUsedAt(?DateTimeImmutable $lastUsedAt): void
    {
        $this->lastUsedAt = $lastUsedAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(DateTimeImmutable $when): void
    {
        $this->revokedAt ??= $when;
    }

    /**
     * Active = not revoked and not past its expiry.
     */
    public function isActive(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            return false;
        }

        return !($this->expiresAt instanceof DateTimeImmutable) || $this->expiresAt > $now;
    }
}
