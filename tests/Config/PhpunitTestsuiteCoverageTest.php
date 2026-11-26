<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Config;

use DOMDocument;
use DOMElement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function count;
use function is_file;
use function rtrim;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function trim;

/**
 * Every test file must belong to a testsuite of phpunit.xml.dist: CI runs
 * suites (`--testsuite unit`, `--testsuite integration,controller,...`), so a
 * file that no suite includes is never run at all. Six such files went unnoticed
 * until #1014.
 *
 * @internal
 */
#[CoversNothing]
final class PhpunitTestsuiteCoverageTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /**
     * Test files that are deliberately outside phpunit.xml.dist. An entry ending
     * in "/" covers the whole directory.
     */
    private const array NOT_IN_A_SUITE = [
        // A PHPat rule set, not a PHPUnit test: the lint job runs it through
        // PHPStan (config/quality/phpat.neon).
        'tests/Architecture/ArchitectureTest.php',
        // Timing benchmarks with their own configuration,
        // config/testing/phpunit-performance.xml (composer perf:*).
        'tests/Performance/',
    ];

    public function testEveryTestFileIsInATestsuite(): void
    {
        $covered = $this->filesInTestsuites();
        // A parser that resolves nothing would report every file as missing,
        // but one that resolves everything would pass silently: pin a floor.
        self::assertGreaterThan(200, count($covered));

        $missing = [];
        foreach ($this->testFiles() as $file) {
            if (!isset($covered[$file]) && !$this->isAllowListed($file)) {
                $missing[] = $file;
            }
        }

        self::assertSame([], $missing, 'Test files in no testsuite of phpunit.xml.dist; add them to a suite CI runs.');
    }

    public function testEveryAllowListEntryStillExists(): void
    {
        foreach (self::NOT_IN_A_SUITE as $entry) {
            self::assertFileExists(self::ROOT . '/' . rtrim($entry, '/'), $entry);
        }
    }

    private function isAllowListed(string $file): bool
    {
        foreach (self::NOT_IN_A_SUITE as $entry) {
            if ($file === $entry || (str_ends_with($entry, '/') && str_starts_with($file, $entry))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true> relative path => true
     */
    private function filesInTestsuites(): array
    {
        $document = new DOMDocument();
        self::assertTrue($document->load(self::ROOT . '/phpunit.xml.dist'));

        $covered = [];
        foreach ($document->getElementsByTagName('testsuite') as $suite) {
            $included = [];
            $excluded = [];
            foreach ($suite->childNodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }

                $path = rtrim(trim($node->textContent), '/');
                match ($node->tagName) {
                    'directory' => $included += $this->filesIn($path, '' !== $node->getAttribute('suffix') ? $node->getAttribute('suffix') : 'Test.php', $node->getAttribute('prefix')),
                    'file' => $included[$path] = true,
                    'exclude' => $excluded[] = $path,
                    default => null,
                };
            }

            foreach (array_keys($included) as $file) {
                if (!$this->isExcluded($file, $excluded)) {
                    $covered[$file] = true;
                }
            }
        }

        return $covered;
    }

    /**
     * @param list<string> $excluded
     */
    private function isExcluded(string $file, array $excluded): bool
    {
        foreach ($excluded as $path) {
            if ($file === $path || str_starts_with($file, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function filesIn(string $directory, string $suffix, string $prefix): array
    {
        $files = [];
        foreach ($this->phpFilesUnder($directory) as $file) {
            $name = substr($file, (int) strrpos($file, '/') + 1);
            if (str_ends_with($name, $suffix) && str_starts_with($name, $prefix)) {
                $files[$file] = true;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function testFiles(): array
    {
        $files = [];
        foreach ($this->phpFilesUnder('tests') as $file) {
            if (str_ends_with($file, 'Test.php')) {
                $files[] = $file;
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<string> paths relative to the project root
     */
    private function phpFilesUnder(string $directory): array
    {
        $root = self::ROOT . '/' . $directory;
        if (is_file($root)) {
            return [$directory];
        }

        $files = [];
        /** @var SplFileInfo $info */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $info) {
            if ($info->isFile()) {
                $files[] = $directory . substr($info->getPathname(), strlen($root));
            }
        }

        return $files;
    }
}
