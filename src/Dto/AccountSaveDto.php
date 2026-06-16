<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Account;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: Account::class)]
final readonly class AccountSaveDto
{
    public function __construct(
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid account name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid account name with at least 3 letters.')]
        public string $name = '',
    ) {
    }

    /**
     * @throws BadRequestException
     */
    public static function fromRequest(Request $request): self
    {
        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            name: (string) ($request->request->get('name') ?? ''),
        );
    }
}
