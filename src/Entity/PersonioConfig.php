<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use App\Repository\PersonioConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Company-level Personio API configuration (ADR-024). One active row in practice;
 * the client secret is stored encrypted (TokenEncryptionService) and stripped from
 * API responses via SECRET_KEYS/toSafeArray.
 */
#[ORM\Entity(repositoryClass: PersonioConfigRepository::class)]
#[ORM\Table(name: 'personio_configs')]
class PersonioConfig extends Base
{
    public const array SECRET_KEYS = ['clientSecret'];

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 63, unique: true)]
    protected string $name = '';

    #[ORM\Column(name: 'base_url', type: 'string', length: 255)]
    protected string $baseUrl = '';

    #[ORM\Column(name: 'client_id', type: 'string', length: 255)]
    protected string $clientId = '';

    #[ORM\Column(name: 'client_secret', type: 'text')]
    protected string $clientSecret = '';

    #[ORM\ManyToOne(targetEntity: Project::class)]
    #[ORM\JoinColumn(name: 'absence_project_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    protected ?Project $absenceProject = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    protected bool $active = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = $baseUrl;

        return $this;
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(string $clientId): static
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(string $clientSecret): static
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getAbsenceProject(): ?Project
    {
        return $this->absenceProject;
    }

    public function setAbsenceProject(?Project $absenceProject): static
    {
        $this->absenceProject = $absenceProject;

        return $this;
    }

    public function getActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

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
        foreach (self::SECRET_KEYS as $key) {
            unset($data[$key]);
            unset($data[strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key))]);
        }

        return $data;
    }
}
