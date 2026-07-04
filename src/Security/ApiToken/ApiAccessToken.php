<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Security\ApiToken;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

/**
 * The authenticated security token for an API personal access token (ADR-021).
 * Carries the token's granted scopes so the RequireScope check can gate the
 * request. The roles are the owning user's roles — scopes narrow, roles still
 * apply (the intersection is enforced by the existing IsGranted checks + the
 * scope voter).
 */
final class ApiAccessToken extends AbstractToken
{
    /**
     * @param list<string> $roles  the owning user's roles
     * @param list<string> $scopes the token's granted scopes
     */
    public function __construct(User $user, array $roles, private readonly array $scopes)
    {
        parent::__construct($roles);
        $this->setUser($user);
    }

    /**
     * @return list<string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }
}
