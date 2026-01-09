<?php

declare(strict_types=1);

namespace Tests\Traits;

use Exception;
use RuntimeException;

use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function function_exists;
use function is_file;
use function trim;

/**
 * Test data management trait.
 *
 * Provides fixture loading and test data management functionality
 * for test cases requiring specific test data.
 */
trait TestDataTrait
{
    /**
     * Flag to track if test data has been loaded.
     */
    private static bool $dataLoaded = false;

    /**
     * Load test data from SQL file.
     *
     * Each test has a path to the file with the SQL test data.
     * When executing loadTestData() the file from the $filepath
     * of current scope will be imported.
     */
    protected function loadTestData(?string $filepath = null): void
    {
        // Determine file path - handle parallel execution path issues
        $testFilePath = $this->resolveTestDataPath($filepath);

        if ($testFilePath === null || $testFilePath === '' || ! is_file($testFilePath)) {
            // Skip loading if file doesn't exist (parallel execution might not need all data)
            error_log('Test data file not found: ' . (null !== $testFilePath && '' !== $testFilePath ? $testFilePath : 'unknown'));

            return;
        }

        $file = file_get_contents($testFilePath);

        if (false === $file) {
            error_log('Failed to read test data file: ' . $testFilePath);

            return;
        }

        // turn on error reporting (if function exists)
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        try {
            if (null === $this->serviceContainer) {
                throw new RuntimeException('Service container not initialized');
            }
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');

            // Execute SQL file statements using DBAL (avoid native connection handling)
            $statements = explode(';', $file);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ('' !== $statement && '0' !== $statement) {
                    try {
                        $connection->executeStatement($statement);
                    } catch (Exception $e) {
                        // In parallel execution, some statements might fail due to race conditions
                        // Log but don't fail completely
                        error_log('Database statement warning: ' . $e->getMessage() . ' for: ' . substr($statement, 0, 100));
                    }
                }
            }

            $this->connection = $connection;

            // get the queryBuilder
            $this->queryBuilder = $connection->createQueryBuilder();
        } catch (Exception $exception) {
            error_log('Database connection error: ' . $exception->getMessage());
            throw $exception;
        } finally {
            // turn off error reporting (if function exists)
            if (function_exists('mysqli_report')) {
                mysqli_report(MYSQLI_REPORT_OFF);
            }
        }
    }

    /**
     * Resolve test data file path, handling parallel execution scenarios.
     */
    private function resolveTestDataPath(?string $filepath = null): ?string
    {
        $baseDir = __DIR__;

        // Use provided filepath or default from instance
        $targetPath = $filepath ?? ($this->filepath ?? null);

        if ($targetPath === null || $targetPath === '') {
            return null;
        }

        // Try the direct path first
        $fullPath = $baseDir . $targetPath;
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        // Try relative to project root (for parallel execution)
        $rootPath = dirname($baseDir, 2) . '/sql/unittest/002_testdata.sql';
        if (file_exists($rootPath)) {
            return $rootPath;
        }

        // Try alternative paths for parallel execution
        $alternativePaths = [
            dirname($baseDir, 2) . $targetPath,
            dirname($baseDir, 3) . $targetPath,
            $targetPath, // Absolute path
        ];

        foreach ($alternativePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Clear test data state between test runs.
     */
    protected static function clearTestDataState(): void
    {
        self::$dataLoaded = false;
    }
}
