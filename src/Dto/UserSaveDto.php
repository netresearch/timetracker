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
    /** The account authenticates against its own local password hash. */
    public const string AUTH_LOCAL = 'local';

    /** The account authenticates against the directory (no local password). */
    public const string AUTH_LDAP = 'ldap';

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
         * Explicit authentication source: 'local' (own password) or 'ldap' (the
         * directory). Replaces the old implicit clearPassword flag — the admin now
         * states the intent and the server enforces it (a local account must end up
         * with a password). Excluded from mapping; applied in SaveUserAction.
         *
         * NULL means the field was omitted (a client that predates this control):
         * SaveUserAction then leaves the existing account untouched — a bare edit
         * must never silently downgrade an existing LOCAL account to the directory.
         * Only an explicit 'ldap' clears the hash.
         */
        #[Map(if: false)]
        public ?string $authSource = null,

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
            // Preserve "omitted" as null (legacy clients) rather than coercing to a
            // value — SaveUserAction treats null as "leave the auth source as-is".
            authSource: $request->request->has('authSource') ? (string) $request->request->get('authSource') : null,
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
     * The authentication-source block accepts one coherent intent:
     *  - authSource null (omitted) → legacy client: no source change, a supplied
     *    password is still length-checked and sets the account local;
     *  - authSource 'ldap' → directory account, so no password may be supplied;
     *  - authSource 'local', password set   → set it (min. length floor applies);
     *  - authSource 'local', password empty → keep the existing hash (SaveUserAction
     *    rejects this when the account has none yet — that check needs the entity).
     *
     * A non-null value other than local/ldap is rejected; supplying a password while
     * choosing LDAP is contradictory and rejected here. The length floor is basic
     * hygiene; a full complexity policy is out of scope (see ADR-018).
     */
    #[Assert\Callback]
    public function validatePassword(ExecutionContextInterface $executionContext): void
    {
        if (null !== $this->authSource && self::AUTH_LOCAL !== $this->authSource && self::AUTH_LDAP !== $this->authSource) {
            $executionContext->buildViolation('Authentication source must be either local or LDAP.')
                ->atPath('authSource')
                ->addViolation();

            return;
        }

        if ('' === $this->password) {
            return;
        }

        if (self::AUTH_LDAP === $this->authSource) {
            $executionContext->buildViolation('An LDAP account has no local password — choose local authentication to set one.')
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
