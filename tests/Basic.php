<?php

declare(strict_types=1);

namespace Tests;

/**
 * @internal
 *
 * @coversNothing
 */
final class Basic extends AbstractWebTestCase
{
    public function testBasic(): void
    {
        // Basic smoke test - just verify the test framework is working
        self::assertTrue(true);
    }
}
