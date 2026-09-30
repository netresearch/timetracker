<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Docker;

use Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shipped nginx configurations must look the PHP container up again instead
 * of once at start. A literal name in fastcgi_pass is resolved when nginx starts
 * and kept, so replacing the PHP container leaves nginx calling the address of the
 * container that is gone and every PHP request answers 502.
 *
 * @internal
 */
#[CoversNothing]
final class NginxConfigTest extends TestCase
{
    /**
     * @return Generator<string, array{string}>
     */
    public static function shippedConfigs(): Generator
    {
        foreach (['default', 'dev', 'e2e'] as $name) {
            yield $name . '.conf' => [__DIR__ . '/../../docker/nginx/' . $name . '.conf'];
        }
    }

    #[DataProvider('shippedConfigs')]
    public function testFastcgiPassUsesAVariable(string $path): void
    {
        $config = $this->read($path);

        self::assertSame(
            1,
            preg_match_all('/^\s*fastcgi_pass\s+\$php_upstream\s*;/m', $config),
            'fastcgi_pass must use $php_upstream, exactly once',
        );
        self::assertSame(
            0,
            preg_match_all('/^\s*fastcgi_pass\s+(?!\$)\S+\s*;/m', $config),
            'fastcgi_pass must not name the upstream literally',
        );
    }

    #[DataProvider('shippedConfigs')]
    public function testUpstreamVariableNamesHostAndPort(string $path): void
    {
        self::assertMatchesRegularExpression(
            '/^\s*set\s+\$php_upstream\s+[a-z0-9][a-z0-9.-]*:\d+\s*;/m',
            $this->read($path),
        );
    }

    #[DataProvider('shippedConfigs')]
    public function testResolverIsDockersEmbeddedDnsWithAShortCache(string $path): void
    {
        self::assertMatchesRegularExpression(
            '/^\s*resolver\s+127\.0\.0\.11\s+valid=\d+s\b/m',
            $this->read($path),
        );
    }

    private function read(string $path): string
    {
        $config = file_get_contents($path);
        self::assertNotFalse($config, $path . ' is not readable');

        return $config;
    }
}
