<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DependencyInjection\Compiler;

use App\DependencyInjection\Compiler\RemoveSensitiveCollectorsPass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
#[CoversClass(RemoveSensitiveCollectorsPass::class)]
final class RemoveSensitiveCollectorsPassTest extends TestCase
{
    public function testUntagsDumpAndConfigCollectorsButKeepsOthers(): void
    {
        $container = new ContainerBuilder();
        $container->register('data_collector.dump', stdClass::class)->addTag('data_collector');
        $container->register('data_collector.config', stdClass::class)->addTag('data_collector');
        $container->register('data_collector.request', stdClass::class)->addTag('data_collector');

        new RemoveSensitiveCollectorsPass()->process($container);

        // Tag removed → dropped from the profiler, no panel, no collection.
        self::assertFalse($container->getDefinition('data_collector.dump')->hasTag('data_collector'));
        self::assertFalse($container->getDefinition('data_collector.config')->hasTag('data_collector'));
        self::assertTrue($container->getDefinition('data_collector.request')->hasTag('data_collector'));
        // Definitions are kept so services that reference them still resolve.
        self::assertTrue($container->hasDefinition('data_collector.dump'));
    }

    public function testIsANoOpWhenCollectorsAbsent(): void
    {
        $container = new ContainerBuilder();

        new RemoveSensitiveCollectorsPass()->process($container);

        self::assertFalse($container->hasDefinition('data_collector.dump'));
    }
}
