<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PersonioConfig;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: PersonioConfig::class)]
final readonly class PersonioConfigSaveDto
{
    public function __construct(
        // The admin CRUD frontend posts id: 0 for a new config (toForm(null)
        // emits id: 0, spread into the payload). Default to 0 so a missing or
        // zero id both mean "create", matching CustomerSaveDto — a nullable
        // default would treat the incoming 0 as an existing id and 404.
        #[Map(if: false)]
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid Personio configuration name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid Personio configuration name with at least 3 letters.')]
        public string $name = '',
        #[Assert\NotBlank(message: 'Please provide the Personio API base URL.')]
        public string $baseUrl = '',
        #[Assert\NotBlank(message: 'Please provide the Personio client id.')]
        public string $clientId = '',
        // Optional: a blank submission keeps the stored (encrypted) secret.
        public string $clientSecret = '',
        #[Map(if: false)]
        public ?int $absenceProjectId = null,
        public bool $active = true,
    ) {
    }
}
