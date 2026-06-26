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
 * Strips the profiler data collectors that can surface env/secret data (dump,
 * config) by removing their `data_collector` tag — they vanish from the profiler
 * and stop collecting, while their service definitions stay intact so anything
 * referencing them (e.g. the VarDumper listener) still resolves. Registered from
 * Kernel::build() in the `profiling` env, BEFORE Symfony's ProfilerPass reads the
 * tags. Kept panels: DB/Time/Memory/Request/Routing/Events/Logs/Cache.
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
                $container->getDefinition($id)->clearTag('data_collector');
            }
        }
    }
}
