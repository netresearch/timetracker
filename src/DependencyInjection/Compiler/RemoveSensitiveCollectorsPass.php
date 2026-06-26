<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Removes the profiler data collectors that can surface env/secret data
 * (dump, config). Registered from Kernel::build() in the `profiling` env only,
 * so the kept panels are DB/Time/Memory/Request/Routing/Events/Logs/Cache.
 */
final class RemoveSensitiveCollectorsPass implements CompilerPassInterface
{
    private const array SENSITIVE_COLLECTORS = [
        'data_collector.dump',
        'data_collector.config',
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach (self::SENSITIVE_COLLECTORS as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }
    }
}
