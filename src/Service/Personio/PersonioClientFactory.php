<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Personio;

use App\Entity\PersonioConfig;
use App\Service\Security\TokenEncryptionService;

/**
 * Builds a {@see PersonioClient} for a config, decrypting the stored client
 * secret (ADR-024 §2 — encrypted at rest, unlike the plaintext Jira secret).
 */
readonly class PersonioClientFactory
{
    public function __construct(private TokenEncryptionService $tokenEncryptionService)
    {
    }

    public function create(PersonioConfig $config): PersonioClient
    {
        return new PersonioClient(
            $config->getBaseUrl(),
            $config->getClientId(),
            $this->tokenEncryptionService->decryptToken($config->getClientSecret()),
        );
    }
}
