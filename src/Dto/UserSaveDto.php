<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Dto;

use App\Entity\User;
use App\Validator\Constraints\UniqueUserAbbr;
use App\Validator\Constraints\UniqueUsername;
use App\Validator\Constraints\ValidUserAbbr;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

// \ Note: Validation handled at controller/service layer to preserve legacy HTTP codes
#[Map(target: User::class)]
final readonly class UserSaveDto
{
    public function __construct(
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid user name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid user name with at least 3 letters.')]
        #[UniqueUsername]
        public string $username = '',
        #[ValidUserAbbr]
        #[UniqueUserAbbr]
        public string $abbr = '',
        public string $type = '',
        public string $locale = '',
        public bool $active = true,

        /**
         * New local password. Empty means "no change". Excluded from the automatic
         * DTO→entity mapping (#[Map(if: false)]): a mapped plain value would be
         * persisted unhashed — it is hashed explicitly in SaveUserAction.
         */
        #[Map(if: false)]
        public string $password = '',

        /**
         * When true, revert the account to LDAP (clear the local password hash).
         * Excluded from mapping; handled explicitly in SaveUserAction.
         */
        #[Map(if: false)]
        public bool $clearPassword = false,

        /** @var list<int|string> */
        #[Map(if: false)]
        public array $teams = [],
    ) {
    }

    /**
     * @throws BadRequestException
     */
    public static function fromRequest(Request $request): self
    {
        /** @var list<int|string> $teams */
        $teams = $request->request->all('teams');

        return new self(
            id: (int) ($request->request->get('id') ?? 0),
            username: (string) ($request->request->get('username') ?? ''),
            abbr: (string) ($request->request->get('abbr') ?? ''),
            type: (string) ($request->request->get('type') ?? ''),
            locale: (string) ($request->request->get('locale') ?? ''),
            active: $request->request->getBoolean('active', true),
            password: (string) ($request->request->get('password') ?? ''),
            clearPassword: $request->request->getBoolean('clearPassword'),
            teams: array_values($teams),
        );
    }

    #[Assert\Callback]
    public function validateTeams(ExecutionContextInterface $executionContext): void
    {
        if ([] === $this->teams) {
            $executionContext->buildViolation('Every user must belong to at least one team')
                ->atPath('teams')
                ->addViolation();
        }
    }

    /**
     * The password block accepts exactly one intent at a time:
     *  - empty password, clearPassword off → no change;
     *  - empty password, clearPassword on  → revert to LDAP;
     *  - password set,   clearPassword off → set it (min. length floor applies).
     *
     * Setting AND clearing together is contradictory, so it is rejected explicitly
     * rather than silently resolved by precedence. The length floor is basic
     * hygiene; a full complexity policy is out of scope (see ADR-018).
     */
    #[Assert\Callback]
    public function validatePassword(ExecutionContextInterface $executionContext): void
    {
        if ('' === $this->password) {
            return;
        }

        if ($this->clearPassword) {
            $executionContext->buildViolation('Choose either setting a new password or clearing it — not both.')
                ->atPath('password')
                ->addViolation();

            return;
        }

        if (mb_strlen($this->password) < 8) {
            $executionContext->buildViolation('Password must be at least 8 characters.')
                ->atPath('password')
                ->addViolation();
        }
    }
}
