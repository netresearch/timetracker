<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebauthnCredentialRepository;
use Doctrine\ORM\Mapping as ORM;
use Webauthn\CredentialRecord;

/**
 * A registered passkey / WebAuthn credential (ADR-018 D3).
 *
 * All ceremony fields (credential id, public key, sign counter, transports,
 * AAGUID, trust path, backup/UV flags) are inherited from {@see CredentialRecord},
 * a Doctrine mapped-superclass the bundle registers automatically; this subclass
 * only adds the local surrogate id so the rows are addressable. The `userHandle`
 * column ties the credential to a {@see User} via that user's non-enumerable
 * webauthn_user_handle (never the integer PK).
 */
#[ORM\Entity(repositoryClass: WebauthnCredentialRepository::class)]
#[ORM\Table(name: 'webauthn_credentials')]
class WebauthnCredential extends CredentialRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
