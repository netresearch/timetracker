<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DependencyInjection;

use Closure;
use Override;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_string;
use function trim;

/**
 * Resolves %env(origins:NAME)% to the comma-separated entries of NAME, trimmed
 * and without empty ones (the built-in csv: processor does neither).
 */
final class OriginListEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * @return non-empty-list<string>
     */
    #[Override]
    public function getEnv(string $prefix, string $name, Closure $getEnv): array
    {
        $value = $getEnv($name);

        $origins = array_values(array_filter(
            array_map(trim(...), explode(',', is_string($value) ? $value : '')),
            static fn (string $origin): bool => '' !== $origin,
        ));

        // Without any entry the bundle's CheckAllowedOrigins skips its list and
        // accepts the RP ID's host on every port. The empty string matches no
        // origin, so an empty variable keeps rejecting every ceremony.
        return [] === $origins ? [''] : $origins;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public static function getProvidedTypes(): array
    {
        return ['origins' => 'array'];
    }
}
