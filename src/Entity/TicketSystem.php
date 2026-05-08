<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

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
     * @var string $ticketUrl
     */
    #[ORM\Column(name: 'ticketurl', type: 'string', length: 255, nullable: false)]
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
    public function setTicketUrl(string $ticketUrl): static
    {
        $this->ticketUrl = $ticketUrl;

        return $this;
    }

    /**
     * Get url pointing to a ticket.
     */
    public function getTicketUrl(): string
    {
        return $this->ticketUrl;
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
}
