<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use App\Repository\UserTicketsystemRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;

#[ORM\Entity(repositoryClass: UserTicketsystemRepository::class)]
#[ORM\Table(name: 'users_ticket_systems')]
#[ORM\Index(name: 'idx_uts_remote_account', columns: ['ticket_system_id', 'remote_account_id'])]
class UserTicketsystem extends Base
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: true)]
    protected ?TicketSystem $ticketSystem = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'userTicketsystems')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    protected ?User $user = null;

    /**
     * @var string
     *             Encrypted OAuth access token
     */
    #[ORM\Column(name: 'accesstoken', type: 'text')]
    protected $accessToken;

    /**
     * @var string
     *             Encrypted OAuth token secret
     */
    #[ORM\Column(name: 'tokensecret', type: 'text')]
    protected $tokenSecret;

    /**
     * Encrypted OAuth2 refresh token (Cloud only; rotates on every refresh).
     */
    #[ORM\Column(name: 'refresh_token', type: 'text', nullable: true)]
    protected ?string $refreshToken = null;

    /**
     * Absolute expiry of the OAuth2 access token (Cloud only).
     */
    #[ORM\Column(name: 'token_expires_at', type: 'datetime_immutable', nullable: true)]
    protected ?DateTimeImmutable $tokenExpiresAt = null;

    #[ORM\Column(name: 'avoidconnection', type: 'boolean', options: ['default' => false])]
    protected bool $avoidConnection = false;

    /**
     * Jira account identity (Cloud accountId or Server username) this TT user maps to (ADR-023 §3).
     */
    #[ORM\Column(name: 'remote_account_id', type: 'string', length: 255, nullable: true)]
    protected ?string $remoteAccountId = null;

    /**
     * Author opted their own worklogs into unattended cron sync under their own token (ADR-023 amendment).
     */
    #[ORM\Column(name: 'sync_enabled', type: 'boolean', options: ['default' => false])]
    protected bool $syncEnabled = false;

    /**
     * PO (ROLE_PL/ADMIN) opted into syncing all worklogs their token can access in Jira (ADR-023 amendment).
     */
    #[ORM\Column(name: 'sync_all', type: 'boolean', options: ['default' => false])]
    protected bool $syncAll = false;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return $this
     */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTicketSystem(): ?TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * @return $this
     */
    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * @return $this
     */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @return $this
     */
    public function setAccessToken(#[SensitiveParameter] string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getTokenSecret(): string
    {
        return $this->tokenSecret;
    }

    /**
     * @return $this
     */
    public function setTokenSecret(#[SensitiveParameter] string $tokenSecret): static
    {
        $this->tokenSecret = $tokenSecret;

        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    /**
     * @return $this
     */
    public function setRefreshToken(#[SensitiveParameter] ?string $refreshToken): static
    {
        $this->refreshToken = $refreshToken;

        return $this;
    }

    public function getTokenExpiresAt(): ?DateTimeImmutable
    {
        return $this->tokenExpiresAt;
    }

    /**
     * @return $this
     */
    public function setTokenExpiresAt(?DateTimeImmutable $tokenExpiresAt): static
    {
        $this->tokenExpiresAt = $tokenExpiresAt;

        return $this;
    }

    public function getAvoidConnection(): bool
    {
        return $this->avoidConnection;
    }

    /**
     * @return $this
     */
    public function setAvoidConnection(bool $avoidConnection): static
    {
        $this->avoidConnection = $avoidConnection;

        return $this;
    }

    public function getRemoteAccountId(): ?string
    {
        return $this->remoteAccountId;
    }

    /**
     * @return $this
     */
    public function setRemoteAccountId(?string $remoteAccountId): static
    {
        $this->remoteAccountId = $remoteAccountId;

        return $this;
    }

    public function getSyncEnabled(): bool
    {
        return $this->syncEnabled;
    }

    /**
     * @return $this
     */
    public function setSyncEnabled(bool $syncEnabled): static
    {
        $this->syncEnabled = $syncEnabled;

        return $this;
    }

    public function getSyncAll(): bool
    {
        return $this->syncAll;
    }

    /**
     * @return $this
     */
    public function setSyncAll(bool $syncAll): static
    {
        $this->syncAll = $syncAll;

        return $this;
    }
}
