<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ValueObject;

use function in_array;

/**
 * The OAuth2-style `resource:action` scope taxonomy for API tokens (ADR-021).
 *
 * A token's scopes NARROW the owning user's access — they never expand it (the
 * user's roles are still checked). Enforcement (a #[RequireScope] voter on the
 * Bearer firewall) lands in Phase 2; this class is the single source of valid
 * scope strings and the grant check.
 */
final class ApiScope
{
    /** Grants every scope the owning user can exercise. */
    public const string WILDCARD = '*';

    /** @var list<string> API resource areas (see docs/agent-readiness.md / api.yml) */
    public const array RESOURCES = [
        'entries', 'projects', 'customers', 'activities', 'presets', 'teams',
        'users', 'contracts', 'ticketsystems', 'reporting', 'settings', 'sync',
    ];

    /** @var list<string> */
    public const array ACTIONS = ['read', 'write'];

    /** @var list<string>|null memoised scope list (resources/actions are constant) */
    private static ?array $all = null;

    /**
     * Every valid scope string, including the wildcard. Computed once.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        if (null !== self::$all) {
            return self::$all;
        }

        $scopes = [self::WILDCARD];
        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $scopes[] = $resource . ':' . $action;
            }
        }

        return self::$all = $scopes;
    }

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * Whether a token carrying $granted scopes satisfies the $required scope.
     * The wildcard satisfies anything.
     *
     * @param list<string> $granted
     */
    public static function grants(array $granted, string $required): bool
    {
        return in_array(self::WILDCARD, $granted, true) || in_array($required, $granted, true);
    }
}
