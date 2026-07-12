<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeploymentType;
use App\Enum\TicketSystemType;
use App\Model\Base;
use App\Repository\TicketSystemRepository;
use Doctrine\ORM\Mapping as ORM;
use SensitiveParameter;

/**
 * App\Entity\TicketSystem.
 */
#[ORM\Entity(repositoryClass: TicketSystemRepository::class)]
#[ORM\Table(name: 'ticket_systems')]
class TicketSystem extends Base
{
    /**
     * @var int $id
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string $name
     */
    #[ORM\Column(type: 'string', length: 31, unique: true)]
    protected $name;

    /**
     * @var bool $bookTime;
     */
    #[ORM\Column(name: 'book_time', type: 'boolean', nullable: false, options: ['default' => 0])]
    protected $bookTime = false;

    /**
     * Stored as string to handle unknown/user-entered values gracefully.
     */
    #[ORM\Column(type: 'string', length: 31)]
    protected string $type = 'JIRA';

    /**
     * @var string $url
     */
    #[ORM\Column(type: 'string', length: 255)]
    protected $url;

    /**
     * @var string|null $ticketUrl
     */
    #[ORM\Column(name: 'ticketurl', type: 'string', length: 255, nullable: true)]
    protected $ticketUrl;

    /**
     * @var string $login
     */
    #[ORM\Column(type: 'string', length: 63)]
    protected $login;

    /**
     * @var string $password
     */
    #[ORM\Column(type: 'string', length: 63)]
    protected $password;

    #[ORM\Column(name: 'public_key', type: 'text')]
    protected string $publicKey = '';

    #[ORM\Column(name: 'private_key', type: 'text')]
    protected string $privateKey = '';

    #[ORM\Column(name: 'oauth_consumer_key', type: 'string', length: 255, nullable: true)]
    protected ?string $oauthConsumerKey = null;

    #[ORM\Column(name: 'oauth_consumer_secret', type: 'string', length: 255, nullable: true)]
    protected ?string $oauthConsumerSecret = null;

    /**
     * Deployment discriminator (SERVER = OAuth 1.0a Server/DC, CLOUD = OAuth 2.0
     * 3LO). Stored as string to handle unknown/user-entered values gracefully.
     */
    #[ORM\Column(name: 'deployment_type', type: 'string', length: 15, options: ['default' => 'SERVER'])]
    protected string $deploymentType = 'SERVER';

    #[ORM\Column(name: 'oauth2_client_id', type: 'string', length: 255, nullable: true)]
    protected ?string $oauth2ClientId = null;

    #[ORM\Column(name: 'oauth2_client_secret', type: 'string', length: 255, nullable: true)]
    protected ?string $oauth2ClientSecret = null;

    /**
     * Atlassian cloud id, resolved once at first auth via accessible-resources.
     * Server-side only (never admin-entered); non-secret.
     */
    #[ORM\Column(name: 'cloud_id', type: 'string', length: 64, nullable: true)]
    protected ?string $cloudId = null;

    /**
     * Activity assigned to worklogs auto-imported by cron sync; null disables unattended import.
     */
    #[ORM\ManyToOne(targetEntity: Activity::class)]
    #[ORM\JoinColumn(name: 'sync_default_activity_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Activity $syncDefaultActivity = null;

    /**
     * Opt-in gate (ADR-026 P3): when true, a worklog import may ad-hoc auto-create
     * a Project + derived Customer for an unresolved Jira prefix — but only when
     * the derivation yields exactly one confident customer. Default false keeps
     * the park-on-unresolved behaviour, so auto-create (billing data) is opt-in.
     */
    #[ORM\Column(name: 'auto_import_unresolved_projects', type: 'boolean', nullable: false, options: ['default' => 0])]
    protected bool $autoImportUnresolvedProjects = false;

    /**
     * Credential fields that must never leave the server. They are needed only
     * server-side, so both the list endpoint (GetTicketSystemsAction) and the
     * save response (SaveTicketSystemAction) strip them. Base::toArray() emits
     * each protected property in both camelCase and snake_case, so both
     * spellings are listed here.
     *
     * @var list<string>
     */
    public const array SECRET_KEYS = [
        'password',
        'publicKey', 'public_key',
        'privateKey', 'private_key',
        'oauthConsumerKey', 'oauth_consumer_key',
        'oauthConsumerSecret', 'oauth_consumer_secret',
        'oauth2ClientSecret', 'oauth2_client_secret',
    ];

    /**
     * Get id.
     *
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set bookTime.
     *
     * @return $this
     */
    public function setBookTime(bool $bookTime): static
    {
        $this->bookTime = $bookTime;

        return $this;
    }

    /**
     * Get bookTime.
     */
    public function getBookTime(): bool
    {
        return $this->bookTime;
    }

    /**
     * Set type.
     *
     * @return $this
     */
    public function setType(TicketSystemType|string $type): static
    {
        $this->type = $type instanceof TicketSystemType ? $type->value : $type;

        return $this;
    }

    /**
     * Get type as enum (unknown values fallback to UNKNOWN).
     */
    public function getType(): TicketSystemType
    {
        return TicketSystemType::tryFrom($this->type) ?? TicketSystemType::UNKNOWN;
    }

    /**
     * Get raw type string (for display/debugging unknown types).
     */
    public function getTypeRaw(): string
    {
        return $this->type;
    }

    /**
     * Set url.
     *
     * @return $this
     */
    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get url.
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Set the ticket url.
     *
     * @return $this
     */
    public function setTicketUrl(?string $ticketUrl): static
    {
        $this->ticketUrl = $ticketUrl;

        return $this;
    }

    /**
     * Get url pointing to a ticket.
     *
     * The database column is nullable (legacy rows): normalize NULL to an
     * empty string for the string-building callers.
     */
    public function getTicketUrl(): string
    {
        return $this->ticketUrl ?? '';
    }

    /**
     * Set login.
     *
     * @return $this
     */
    public function setLogin(string $login): static
    {
        $this->login = $login;

        return $this;
    }

    /**
     * Get login.
     */
    public function getLogin(): string
    {
        return $this->login;
    }

    /**
     * Set password.
     *
     * @return $this
     */
    public function setPassword(#[SensitiveParameter] string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password.
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    /**
     * Get public key.
     *
     * @return string $publicKey
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Set private key.
     *
     * @return $this
     */
    public function setPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    /**
     * Get private key.
     *
     * @return string $privateKey
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getOauthConsumerKey(): ?string
    {
        return $this->oauthConsumerKey;
    }

    /**
     * @return $this
     */
    public function setOauthConsumerKey(?string $oauthConsumerKey): static
    {
        $this->oauthConsumerKey = $oauthConsumerKey;

        return $this;
    }

    public function getOauthConsumerSecret(): ?string
    {
        return $this->oauthConsumerSecret;
    }

    /**
     * @return $this
     */
    public function setOauthConsumerSecret(?string $oauthConsumerSecret): static
    {
        $this->oauthConsumerSecret = $oauthConsumerSecret;

        return $this;
    }

    /**
     * Set deployment type.
     *
     * @return $this
     */
    public function setDeploymentType(DeploymentType|string $deploymentType): static
    {
        $this->deploymentType = $deploymentType instanceof DeploymentType ? $deploymentType->value : $deploymentType;

        return $this;
    }

    /**
     * Get deployment type as enum (unknown values fallback to SERVER).
     */
    public function getDeploymentType(): DeploymentType
    {
        return DeploymentType::tryFrom($this->deploymentType) ?? DeploymentType::SERVER;
    }

    /**
     * Get raw deployment type string (for display/debugging unknown types).
     */
    public function getDeploymentTypeRaw(): string
    {
        return $this->deploymentType;
    }

    public function getOauth2ClientId(): ?string
    {
        return $this->oauth2ClientId;
    }

    /**
     * @return $this
     */
    public function setOauth2ClientId(?string $oauth2ClientId): static
    {
        $this->oauth2ClientId = $oauth2ClientId;

        return $this;
    }

    public function getOauth2ClientSecret(): ?string
    {
        return $this->oauth2ClientSecret;
    }

    /**
     * @return $this
     */
    public function setOauth2ClientSecret(?string $oauth2ClientSecret): static
    {
        $this->oauth2ClientSecret = $oauth2ClientSecret;

        return $this;
    }

    public function getCloudId(): ?string
    {
        return $this->cloudId;
    }

    /**
     * @return $this
     */
    public function setCloudId(?string $cloudId): static
    {
        $this->cloudId = $cloudId;

        return $this;
    }

    public function getSyncDefaultActivity(): ?Activity
    {
        return $this->syncDefaultActivity;
    }

    /**
     * @return $this
     */
    public function setSyncDefaultActivity(?Activity $syncDefaultActivity): static
    {
        $this->syncDefaultActivity = $syncDefaultActivity;

        return $this;
    }

    public function getAutoImportUnresolvedProjects(): bool
    {
        return $this->autoImportUnresolvedProjects;
    }

    /**
     * @return $this
     */
    public function setAutoImportUnresolvedProjects(bool $autoImportUnresolvedProjects): static
    {
        $this->autoImportUnresolvedProjects = $autoImportUnresolvedProjects;

        return $this;
    }

    /**
     * toArray() without the secret credential fields — the only shape that may
     * be sent to a client.
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        foreach (self::SECRET_KEYS as $secretKey) {
            unset($data[$secretKey]);
        }

        return $data;
    }
}
