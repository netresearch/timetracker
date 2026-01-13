<?php

declare(strict_types=1);

namespace Tests\Performance;

use DateTime;
use Exception;
use ReflectionClass;
use ReflectionMethod;

use function array_slice;
use function assert;
use function count;
use function dirname;
use function ini_get;
use function is_array;
use function is_scalar;
use function is_string;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;

/**
 * Performance benchmark runner and reporter.
 *
 * Executes performance tests and generates detailed reports
 * for export functionality analysis and regression detection.
 *
 * @internal
 */
final class PerformanceBenchmarkRunner
{
    /**
     * @var array<string, mixed>
     */
    private array $benchmarkResults = [];

    private string $reportPath;

    /**
     * @var array<string, int|float>
     */
    private array $regressionThresholds;

    public function __construct(?string $reportPath = null)
    {
        $this->reportPath = $reportPath ?? __DIR__ . '/../../var/performance-report-' . date('Y-m-d-H-i-s') . '.json';
        $this->setupRegressionThresholds();
    }

    /**
     * Set up regression detection thresholds (percentage increase that triggers warning).
     */
    private function setupRegressionThresholds(): void
    {
        $this->regressionThresholds = [
            'execution_time' => 20, // 20% increase in execution time
            'memory_usage' => 25,   // 25% increase in memory usage
            'throughput' => -15,    // 15% decrease in throughput (negative = worse)
        ];
    }

    /**
     * Run all performance benchmarks.
     */
    /**
     * @return array<string, mixed>
     */
    public function runAllBenchmarks(): array
    {
        $this->benchmarkResults = [
            'timestamp' => (new DateTime())->format('c'),
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'environment' => [
                'os' => PHP_OS_FAMILY,
                'php_sapi' => PHP_SAPI,
            ],
            'benchmarks' => [],
        ];

        echo "🚀 Starting Export Performance Benchmarks...\n\n";

        // Run ExportService benchmarks
        $this->runBenchmarkSuite('ExportService', ExportPerformanceTest::class);

        // Run ExportAction benchmarks
        $this->runBenchmarkSuite('ExportAction', ExportActionPerformanceTest::class);

        // Generate reports
        $this->generateJsonReport();
        $this->generateHumanReadableReport();
        $this->detectPerformanceRegressions();

        echo "\n✅ Benchmarks completed. Reports saved to:\n";
        echo "   JSON: {$this->reportPath}\n";
        echo '   Text: ' . str_replace('.json', '.txt', $this->reportPath) . "\n\n";

        return $this->benchmarkResults;
    }

    /**
     * Run a specific benchmark suite.
     */
    private function runBenchmarkSuite(string $suiteName, string $testClass): void
    {
        echo "📊 Running {$suiteName} benchmarks...\n";

        $testMethods = $this->getPerformanceTestMethods($testClass);
        $suiteResults = [];

        foreach ($testMethods as $method) {
            echo "  ⏱️  {$method}... ";

            $result = $this->runSingleBenchmark($testClass, $method);
            $suiteResults[$method] = $result;

            echo $this->formatBenchmarkResult($result) . "\n";
        }

        // Fix offsetAccess.nonOffsetAccessible: Ensure benchmarks key exists and is array
        if (! isset($this->benchmarkResults['benchmarks'])) {
            $this->benchmarkResults['benchmarks'] = [];
        }
        if (! is_array($this->benchmarkResults['benchmarks'])) {
            $this->benchmarkResults['benchmarks'] = [];
        }
        $this->benchmarkResults['benchmarks'][$suiteName] = $suiteResults;
        echo "\n";
    }

    /**
     * Get performance test methods from a test class.
     */
    /**
     * @return array<int, string>
     */
    private function getPerformanceTestMethods(string $testClass): array
    {
        // Fix argument.type: Ensure $testClass is a valid class string
        if (! class_exists($testClass)) {
            return [];
        }

        $reflection = new ReflectionClass($testClass);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test')
                && str_contains($method->getName(), 'Performance')) {
                $methods[] = $method->getName();
            }
        }

        return $methods;
    }

    /**
     * Run a single benchmark test.
     */
    /**
     * @return array<string, mixed>
     */
    private function runSingleBenchmark(string $testClass, string $method): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        $startPeakMemory = memory_get_peak_usage(true);

        try {
            // Create test instance and run method
            /** @var object $test */
            $test = new $testClass();
            $setUpCallable = [$test, 'setUp'];
            if (is_callable($setUpCallable)) {
                $setUpCallable();
            }
            $methodCallable = [$test, $method];
            if (is_callable($methodCallable)) {
                $methodCallable();
            }
            $tearDownCallable = [$test, 'tearDown'];
            if (is_callable($tearDownCallable)) {
                $tearDownCallable();
            }

            $success = true;
            $error = null;
        } catch (Exception $e) {
            $success = false;
            $error = $e->getMessage();
        }

        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);
        $endPeakMemory = memory_get_peak_usage(true);

        return [
            'success' => $success,
            'error' => $error,
            'execution_time_ms' => round(($endTime - $startTime) * 1000, 2),
            'memory_usage_bytes' => $endMemory - $startMemory,
            'peak_memory_usage_bytes' => $endPeakMemory - $startPeakMemory,
            'timestamp' => (new DateTime())->format('c'),
        ];
    }

    /**
     * Format benchmark result for console output.
     */
    /**
     * @param array<string, mixed> $result
     */
    private function formatBenchmarkResult(array $result): string
    {
        assert(isset($result['success']));
        if (true !== $result['success']) {
            assert(isset($result['error']));
            // Fix cast.string #1: Safe string casting with type validation
            $errorValue = $result['error'] ?? 'unknown error';
            $errorStr = is_scalar($errorValue) ? (string) $errorValue : 'unknown error';

            return '❌ FAILED: ' . $errorStr;
        }

        assert(isset($result['execution_time_ms'], $result['memory_usage_bytes']));
        $time = $result['execution_time_ms'];
        $memory = number_format((is_numeric($result['memory_usage_bytes']) ? (float) $result['memory_usage_bytes'] : 0.0) / 1024 / 1024, 2);

        $timeStr = is_scalar($time) ? (string) $time : '0';

        return '✅ ' . $timeStr . "ms, {$memory}MB";
    }

    /**
     * Generate JSON report for automated analysis.
     */
    private function generateJsonReport(): void
    {
        $reportDir = dirname($this->reportPath);
        if (! is_dir($reportDir)) {
            mkdir($reportDir, 0o777, true);
        }

        file_put_contents(
            $this->reportPath,
            json_encode($this->benchmarkResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * Generate human-readable text report.
     */
    private function generateHumanReadableReport(): void
    {
        $textReportPath = str_replace('.json', '.txt', $this->reportPath);

        $report = [];
        $report[] = '=== Export Performance Benchmark Report ===';
        assert(isset($this->benchmarkResults['timestamp'], $this->benchmarkResults['php_version'], $this->benchmarkResults['memory_limit']));

        // Fix cast.string #3: Safe string casting with type validation
        $timestampValue = $this->benchmarkResults['timestamp'] ?? 'unknown';
        $timestampStr = is_scalar($timestampValue) ? (string) $timestampValue : 'unknown';
        $report[] = 'Generated: ' . $timestampStr;

        // Fix cast.string #4: Safe string casting with type validation
        $phpVersionValue = $this->benchmarkResults['php_version'] ?? 'unknown';
        $phpVersionStr = is_scalar($phpVersionValue) ? (string) $phpVersionValue : 'unknown';
        $report[] = 'PHP Version: ' . $phpVersionStr;

        // Fix cast.string #5: Safe string casting with type validation
        $memoryLimitValue = $this->benchmarkResults['memory_limit'] ?? 'unknown';
        $memoryLimitStr = is_scalar($memoryLimitValue) ? (string) $memoryLimitValue : 'unknown';
        $report[] = 'Memory Limit: ' . $memoryLimitStr;

        // Fix offsetAccess.nonOffsetAccessible: Check if environment and os key exist
        if (isset($this->benchmarkResults['environment']) && is_array($this->benchmarkResults['environment'])) {
            // Fix cast.string #6: Safe string casting with type validation
            $osValue = $this->benchmarkResults['environment']['os'] ?? 'unknown';
            $osStr = is_scalar($osValue) ? (string) $osValue : 'unknown';
            $report[] = 'OS: ' . $osStr;
        } else {
            $report[] = 'OS: unknown';
        }
        $report[] = '';

        // Fix offsetAccess.nonOffsetAccessible: Check if benchmarks key exists and is array
        $benchmarks = $this->benchmarkResults['benchmarks'] ?? [];
        if (! is_array($benchmarks)) {
            $benchmarks = [];
        }

        foreach ($benchmarks as $suiteName => $suite) {
            $report[] = "--- {$suiteName} Benchmarks ---";

            // Fix offsetAccess.nonOffsetAccessible: Check if suite is array
            if (! is_array($suite)) {
                $report[] = 'Invalid suite data';

                continue;
            }

            foreach ($suite as $testName => $result) {
                // Fix offsetAccess.nonOffsetAccessible: Check if result is array and has required keys
                if (! is_array($result)) {
                    $report[] = sprintf('❌ %-40s Invalid result data', $testName);

                    continue;
                }

                $status = isset($result['success']) && true === $result['success'] ? '✅' : '❌';
                $time = $result['execution_time_ms'] ?? 0;
                $memoryBytes = $result['memory_usage_bytes'] ?? 0;
                $memory = number_format((is_numeric($memoryBytes) ? (float) $memoryBytes : 0.0) / 1024 / 1024, 2);

                // Cast array key to string (array keys are always int|string)
                $testNameStr = (string) $testName;

                $report[] = sprintf(
                    '%s %-40s %6.1fms %8sMB',
                    $status,
                    $testNameStr,
                    is_numeric($time) ? (float) $time : 0.0,
                    $memory,
                );

                // Fix offsetAccess.nonOffsetAccessible: Check if success and error keys exist
                if (! isset($result['success']) || true !== $result['success']) {
                    if (isset($result['error'])) {
                        // Fix cast.string #8: Safe string casting with type validation (duplicate of #1)
                        $errorValue = $result['error'] ?? 'unknown';
                        $errorStr = is_scalar($errorValue) ? (string) $errorValue : 'unknown';
                        $report[] = '    Error: ' . $errorStr;
                    }
                }
            }
            $report[] = '';
        }

        // Add summary statistics
        $report[] = '--- Performance Summary ---';
        $this->addSummaryStatistics($report);

        file_put_contents($textReportPath, implode("\n", $report));
    }

    /**
     * Add summary statistics to the report.
     */
    /**
     * @param array<int, string> &$report
     */
    private function addSummaryStatistics(array &$report): void
    {
        $allResults = [];

        // Fix offsetAccess.nonOffsetAccessible: Check if benchmarks exists and is array
        $benchmarks = $this->benchmarkResults['benchmarks'] ?? [];
        if (! is_array($benchmarks)) {
            $report[] = 'No benchmark data available.';

            return;
        }

        foreach ($benchmarks as $suite) {
            // Fix offsetAccess.nonOffsetAccessible: Check if suite is array
            if (is_array($suite)) {
                $allResults = array_merge($allResults, array_values($suite));
            }
        }

        // Fix offsetAccess.nonOffsetAccessible: Filter results with proper type checking
        $successfulResults = array_filter($allResults, static fn ($r) => is_array($r) && isset($r['success']) && true === $r['success']);

        if ([] === $successfulResults) {
            $report[] = 'No successful benchmarks to analyze.';

            return;
        }

        $totalTests = count($allResults);
        $successfulTests = count($successfulResults);
        $failedTests = $totalTests - $successfulTests;

        $executionTimes = array_column($successfulResults, 'execution_time_ms');
        $memoryUsages = array_column($successfulResults, 'memory_usage_bytes');

        if ([] === $executionTimes || [] === $memoryUsages) {
            $report[] = 'No valid performance data to analyze.';

            return;
        }

        $report[] = sprintf('Total Tests: %d (✅ %d, ❌ %d)', $totalTests, $successfulTests, $failedTests);
        $report[] = sprintf('Average Execution Time: %.1fms', array_sum($executionTimes) / count($executionTimes));
        /** @var int|float $maxExecutionTime */
        $maxExecutionTime = max($executionTimes);
        $report[] = sprintf('Max Execution Time: %.1fms', $maxExecutionTime);
        $report[] = sprintf('Average Memory Usage: %.2fMB', array_sum($memoryUsages) / count($memoryUsages) / 1024 / 1024);
        /** @var int|float $maxMemoryUsage */
        $maxMemoryUsage = max($memoryUsages);
        $report[] = sprintf('Max Memory Usage: %.2fMB', $maxMemoryUsage / 1024 / 1024);
    }

    /**
     * Detect performance regressions by comparing with historical data.
     */
    private function detectPerformanceRegressions(): void
    {
        $historyFile = dirname($this->reportPath) . '/performance-history.json';

        if (! file_exists($historyFile)) {
            // Create initial history file
            file_put_contents($historyFile, json_encode([$this->benchmarkResults], JSON_PRETTY_PRINT));
            echo "📈 Created initial performance history baseline.\n";

            return;
        }

        $historyContent = file_get_contents($historyFile);
        if (false === $historyContent) {
            return;
        }

        $history = json_decode($historyContent, true);
        if (! is_array($history) || [] === $history) {
            return;
        }

        $lastRun = end($history);
        if (! is_array($lastRun)) {
            return;
        }

        // Fix argument.type: Ensure $lastRun is properly typed as array<string, mixed>
        $validatedLastRun = $this->validateHistoryEntry($lastRun);
        if (null === $validatedLastRun) {
            return;
        }

        $regressions = $this->comparePerformanceResults($validatedLastRun, $this->benchmarkResults);

        if ([] !== $regressions) {
            echo "⚠️  Performance regressions detected:\n";
            foreach ($regressions as $regression) {
                echo "   {$regression}\n";
            }
            echo "\n";
        } else {
            echo "✅ No performance regressions detected.\n";
        }

        // Append current results to history (keep last 10 runs)
        $history[] = $this->benchmarkResults;
        $history = array_slice($history, -10);
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    /**
     * Validate and ensure history entry has correct type structure.
     *
     * @param array<mixed> $entry
     *
     * @return array<string, mixed>|null
     */
    private function validateHistoryEntry(array $entry): ?array
    {
        $validated = [];
        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $validated[$key] = $value;
            }
        }

        // Ensure required keys exist
        if (! isset($validated['benchmarks'])) {
            return null;
        }

        return $validated;
    }

    /**
     * Compare performance results and detect regressions.
     */
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     *
     * @return array<int, string>
     */
    private function comparePerformanceResults(array $baseline, array $current): array
    {
        $regressions = [];

        // Fix offsetAccess.nonOffsetAccessible: Check if benchmarks keys exist and are arrays
        $currentBenchmarks = $current['benchmarks'] ?? [];
        $baselineBenchmarks = $baseline['benchmarks'] ?? [];

        if (! is_array($currentBenchmarks) || ! is_array($baselineBenchmarks)) {
            return $regressions;
        }

        foreach ($currentBenchmarks as $suiteName => $suite) {
            // Fix offsetAccess.nonOffsetAccessible: Check if baseline suite exists
            if (! isset($baselineBenchmarks[$suiteName]) || ! is_array($baselineBenchmarks[$suiteName])) {
                continue;
            }

            // Fix offsetAccess.nonOffsetAccessible: Check if suite is array
            if (! is_array($suite)) {
                continue;
            }

            foreach ($suite as $testName => $result) {
                // Fix offsetAccess.nonOffsetAccessible: Check if baseline result exists and is array
                $baselineResult = $baselineBenchmarks[$suiteName][$testName] ?? null;

                if (! is_array($baselineResult) || ! is_array($result)) {
                    continue;
                }

                // Fix offsetAccess.nonOffsetAccessible: Check if success keys exist
                if (! isset($result['success']) || ! isset($baselineResult['success'])
                    || true !== $result['success'] || true !== $baselineResult['success']) {
                    continue;
                }

                // Check execution time regression
                $baselineTime = $baselineResult['execution_time_ms'] ?? 0;
                $currentTime = $result['execution_time_ms'] ?? 0;
                if (! is_numeric($baselineTime) || ! is_numeric($currentTime)) {
                    continue;
                }
                $timeRegression = $this->calculateRegression(
                    (float) $baselineTime,
                    (float) $currentTime,
                );

                if ($timeRegression > $this->regressionThresholds['execution_time']) {
                    // Cast array keys to string (array keys are always int|string)
                    $suiteNameStr = (string) $suiteName;
                    $testNameStr = (string) $testName;

                    $regressions[] = sprintf(
                        '%s::%s execution time increased by %.1f%% (%.1fms → %.1fms)',
                        $suiteNameStr,
                        $testNameStr,
                        $timeRegression,
                        (float) $baselineTime,
                        (float) $currentTime,
                    );
                }

                // Check memory usage regression
                $baselineMemory = $baselineResult['memory_usage_bytes'] ?? 0;
                $currentMemory = $result['memory_usage_bytes'] ?? 0;
                if (! is_numeric($baselineMemory) || ! is_numeric($currentMemory)) {
                    continue;
                }
                $memoryRegression = $this->calculateRegression(
                    (float) $baselineMemory,
                    (float) $currentMemory,
                );

                if ($memoryRegression > $this->regressionThresholds['memory_usage']) {
                    // Cast array keys to string (array keys are always int|string)
                    $suiteNameStr = (string) $suiteName;
                    $testNameStr = (string) $testName;

                    $regressions[] = sprintf(
                        '%s::%s memory usage increased by %.1f%% (%.2fMB → %.2fMB)',
                        $suiteNameStr,
                        $testNameStr,
                        $memoryRegression,
                        $baselineMemory / 1024 / 1024,
                        $currentMemory / 1024 / 1024,
                    );
                }
            }
        }

        return $regressions;
    }

    /**
     * Calculate percentage regression between baseline and current values.
     */
    private function calculateRegression(float $baseline, float $current): float
    {
        if (0.0 === $baseline) {
            return 0;
        }

        return (($current - $baseline) / $baseline) * 100;
    }

    /**
     * Run benchmarks from command line.
     */
    /**
     * @param array<int, string> $argv
     */
    public static function main(array $argv = []): void
    {
        $reportPath = $argv[1] ?? null;
        $runner = new self($reportPath);

        try {
            $runner->runAllBenchmarks();
            exit(0);
        } catch (Exception $e) {
            echo '❌ Benchmark execution failed: ' . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Allow running from command line
if (PHP_SAPI === 'cli' && isset($argv) && basename(__FILE__) === basename($argv[0])) {
    PerformanceBenchmarkRunner::main($argv);
}
