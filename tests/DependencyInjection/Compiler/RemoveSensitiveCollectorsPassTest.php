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
    public function testRemovesDumpAndConfigCollectorsButKeepsOthers(): void
    {
        $container = new ContainerBuilder();
        $container->register('data_collector.dump', stdClass::class);
        $container->register('data_collector.config', stdClass::class);
        $container->register('data_collector.request', stdClass::class);

        new RemoveSensitiveCollectorsPass()->process($container);

        self::assertFalse($container->hasDefinition('data_collector.dump'));
        self::assertFalse($container->hasDefinition('data_collector.config'));
        self::assertTrue($container->hasDefinition('data_collector.request'));
    }

    public function testIsANoOpWhenCollectorsAbsent(): void
    {
        $container = new ContainerBuilder();

        new RemoveSensitiveCollectorsPass()->process($container);

        self::assertFalse($container->hasDefinition('data_collector.dump'));
    }
}
