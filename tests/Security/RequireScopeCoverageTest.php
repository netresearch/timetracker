<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Security;

use App\Security\ApiToken\RequireScope;
use App\ValueObject\ApiScope;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function class_exists;
use function dirname;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

/**
 * ADR-021 Phase 4 coverage guard: every #[RequireScope(...)] declared on a
 * controller must use a scope from the ApiScope taxonomy — a typo like
 * 'reporting:reed' would otherwise silently make the endpoint unreachable by
 * tokens. Read via reflection (not a regex) so quoting/grouping/formatting of the
 * attribute cannot hide a declaration. A floor count guards against accidental
 * removal of the annotations.
 *
 * @internal
 *
 * @coversNothing
 */
final class RequireScopeCoverageTest extends TestCase
{
    private const string CONTROLLER_NAMESPACE = 'App\\Controller\\';

    public function testEveryRequireScopeUsesAValidScope(): void
    {
        $found = 0;
        foreach ($this->controllerClasses() as $class) {
            $reflection = new ReflectionClass($class);
            $attributes = $reflection->getAttributes(RequireScope::class);
            foreach ($reflection->getMethods() as $method) {
                foreach ($method->getAttributes(RequireScope::class) as $attribute) {
                    $attributes[] = $attribute;
                }
            }

            foreach ($attributes as $attribute) {
                $scope = $attribute->newInstance()->scope;
                ++$found;
                self::assertTrue(
                    ApiScope::isValid($scope),
                    sprintf('Invalid API scope "%s" declared in %s', $scope, $class),
                );
            }
        }

        // Phase 2 (6) + Phase 4 (21) = 27; a floor guards against accidental removal
        // while still allowing new scoped endpoints to be added.
        self::assertGreaterThanOrEqual(27, $found, 'expected the token-facing endpoints to declare #[RequireScope]');
    }

    /**
     * FQCNs of every controller class under src/Controller.
     *
     * @return list<class-string>
     */
    private function controllerClasses(): array
    {
        $baseDir = dirname(__DIR__, 2) . '/src/Controller';
        $classes = [];

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        foreach ($files as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($baseDir) + 1, -strlen('.php'));
            $class = self::CONTROLLER_NAMESPACE . str_replace('/', '\\', $relative);
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
