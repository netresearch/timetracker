<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\DependencyInjection;

use App\DependencyInjection\OriginListEnvVarProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(OriginListEnvVarProcessor::class)]
final class OriginListEnvVarProcessorTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('valueProvider')]
    public function testSplitsTheVariable(string $value, array $expected): void
    {
        $origins = new OriginListEnvVarProcessor()->getEnv('origins', 'ORIGINS', static fn (string $name): string => 'ORIGINS' === $name ? $value : '');

        self::assertSame($expected, $origins);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function valueProvider(): iterable
    {
        yield 'single value' => ['https://tt.example.com', ['https://tt.example.com']];
        yield 'several values' => ['https://tt.example.com,https://tt.example.org', ['https://tt.example.com', 'https://tt.example.org']];
        yield 'whitespace' => [" https://tt.example.com ,\n\thttps://tt.example.org ", ['https://tt.example.com', 'https://tt.example.org']];
        yield 'empty entries' => [',https://tt.example.com, ,https://tt.example.org,', ['https://tt.example.com', 'https://tt.example.org']];
        // One entry that matches nothing: an empty list would switch the bundle's list check off.
        yield 'empty variable' => ['', ['']];
        yield 'separators only' => [' , ', ['']];
    }
}
