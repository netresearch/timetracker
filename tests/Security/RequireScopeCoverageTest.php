<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security;

use App\ValueObject\ApiScope;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function preg_match_all;
use function sprintf;

/**
 * ADR-021 Phase 4 coverage guard: every #[RequireScope('…')] declared on a
 * controller must use a scope from the ApiScope taxonomy (catches typos like
 * 'reporting:reed' that would silently make an endpoint unreachable), and the
 * read/write endpoints must keep declaring their scopes (a floor count so the
 * annotations can't quietly disappear).
 *
 * @internal
 *
 * @coversNothing
 */
final class RequireScopeCoverageTest extends TestCase
{
    public function testEveryRequireScopeUsesAValidScope(): void
    {
        $controllerDir = dirname(__DIR__, 2) . '/src/Controller';
        $found = 0;

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerDir));
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if (false === $source) {
                continue;
            }

            preg_match_all("/#\\[RequireScope\\('([^']+)'\\)\\]/", $source, $matches);
            foreach ($matches[1] as $scope) {
                ++$found;
                self::assertTrue(
                    ApiScope::isValid($scope),
                    sprintf('Invalid API scope "%s" in %s', $scope, $file->getFilename()),
                );
            }
        }

        // Phase 2 (6) + Phase 4 (21) endpoints; a floor guards against accidental removal.
        self::assertGreaterThanOrEqual(25, $found, 'expected the token-facing endpoints to declare #[RequireScope]');
    }
}
