<?php

declare(strict_types=1);

namespace Tests\Performance;

use DateTime;
use RuntimeException;

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
            'benchmarks' => []
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
        echo "   Text: " . str_replace('.json', '.txt', $this->reportPath) . "\n\n";

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
        $reflection = new \ReflectionClass($testClass);
        $methods = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'test') && 
                str_contains($method->getName(), 'Performance')) {
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
            if (method_exists($test, 'setUp')) {
                $test->setUp();
            }
            if (method_exists($test, $method)) {
                $test->$method();
            }
            if (method_exists($test, 'tearDown')) {
                $test->tearDown();
            }
            
            $success = true;
            $error = null;
        } catch (\Exception $e) {
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
        if (!$result['success']) {
            assert(isset($result['error']));
            return "❌ FAILED: {$result['error']}";
        }

        assert(isset($result['execution_time_ms'], $result['memory_usage_bytes']));
        $time = $result['execution_time_ms'];
        $memory = number_format($result['memory_usage_bytes'] / 1024 / 1024, 2);
        
        return "✅ {$time}ms, {$memory}MB";
    }

    /**
     * Generate JSON report for automated analysis.
     */
    private function generateJsonReport(): void
    {
        $reportDir = dirname($this->reportPath);
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0777, true);
        }

        file_put_contents(
            $this->reportPath,
            json_encode($this->benchmarkResults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Generate human-readable text report.
     */
    private function generateHumanReadableReport(): void
    {
        $textReportPath = str_replace('.json', '.txt', $this->reportPath);
        
        $report = [];
        $report[] = "=== Export Performance Benchmark Report ===";
        assert(isset($this->benchmarkResults['timestamp'], $this->benchmarkResults['php_version'], $this->benchmarkResults['memory_limit']));
        $report[] = "Generated: " . $this->benchmarkResults['timestamp'];
        $report[] = "PHP Version: " . $this->benchmarkResults['php_version'];
        $report[] = "Memory Limit: " . $this->benchmarkResults['memory_limit'];
        assert(is_array($this->benchmarkResults['environment']) && isset($this->benchmarkResults['environment']['os']));
        $report[] = "OS: " . $this->benchmarkResults['environment']['os'];
        $report[] = "";

        assert(is_array($this->benchmarkResults['benchmarks']));
        foreach ($this->benchmarkResults['benchmarks'] ?? [] as $suiteName => $suite) {
            $report[] = "--- {$suiteName} Benchmarks ---";

            assert(is_array($suite));
            foreach ($suite as $testName => $result) {
                assert(is_array($result) && isset($result['success']));
                $status = $result['success'] ? '✅' : '❌';
                $time = $result['execution_time_ms'] ?? 0;
                $memory = number_format(($result['memory_usage_bytes'] ?? 0) / 1024 / 1024, 2);
                
                $report[] = sprintf(
                    "%s %-40s %6.1fms %8sMB",
                    $status,
                    $testName,
                    $time,
                    $memory
                );
                
                if (!$result['success'] && isset($result['error'])) {
                    $report[] = "    Error: " . $result['error'];
                }
            }
            $report[] = "";
        }

        // Add summary statistics
        $report[] = "--- Performance Summary ---";
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
        foreach ($this->benchmarkResults['benchmarks'] ?? [] as $suite) {
            if (is_array($suite)) {
                $allResults = array_merge($allResults, array_values($suite));
            }
        }

        $successfulResults = array_filter($allResults, fn($r) => $r['success']);
        
        if (empty($successfulResults)) {
            $report[] = "No successful benchmarks to analyze.";
            return;
        }

        $totalTests = count($allResults);
        $successfulTests = count($successfulResults);
        $failedTests = $totalTests - $successfulTests;

        $executionTimes = array_column($successfulResults, 'execution_time_ms');
        $memoryUsages = array_column($successfulResults, 'memory_usage_bytes');
        
        if (!is_array($executionTimes) || empty($executionTimes) || !is_array($memoryUsages) || empty($memoryUsages)) {
            $report[] = "No valid performance data to analyze.";
            return;
        }

        $report[] = sprintf("Total Tests: %d (✅ %d, ❌ %d)", $totalTests, $successfulTests, $failedTests);
        $report[] = sprintf("Average Execution Time: %.1fms", (float)(array_sum($executionTimes) / count($executionTimes)));
        $report[] = sprintf("Max Execution Time: %.1fms", (float)max($executionTimes));
        $report[] = sprintf("Average Memory Usage: %.2fMB", (float)(array_sum($memoryUsages) / count($memoryUsages) / 1024 / 1024));
        $report[] = sprintf("Max Memory Usage: %.2fMB", (float)(max($memoryUsages) / 1024 / 1024));
    }

    /**
     * Detect performance regressions by comparing with historical data.
     */
    private function detectPerformanceRegressions(): void
    {
        $historyFile = dirname($this->reportPath) . '/performance-history.json';
        
        if (!file_exists($historyFile)) {
            // Create initial history file
            file_put_contents($historyFile, json_encode([$this->benchmarkResults], JSON_PRETTY_PRINT));
            echo "📈 Created initial performance history baseline.\n";
            return;
        }

        $historyContent = file_get_contents($historyFile);
        if ($historyContent === false) {
            return;
        }
        
        $history = json_decode($historyContent, true);
        if (!is_array($history) || empty($history)) {
            return;
        }

        $lastRun = end($history);
        if (!is_array($lastRun)) {
            return;
        }
        $regressions = $this->comparePerformanceResults($lastRun, $this->benchmarkResults);

        if (!empty($regressions)) {
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
        if (is_array($history)) {
            $history = array_slice($history, -10);
        }
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
    }

    /**
     * Compare performance results and detect regressions.
     */
    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<int, string>
     */
    private function comparePerformanceResults(array $baseline, array $current): array
    {
        $regressions = [];

        foreach ($current['benchmarks'] ?? [] as $suiteName => $suite) {
            if (!isset($baseline['benchmarks'][$suiteName])) {
                continue;
            }

            foreach ($suite as $testName => $result) {
                $baselineResult = $baseline['benchmarks'][$suiteName][$testName] ?? null;
                
                if (!$baselineResult || !$result['success'] || !$baselineResult['success']) {
                    continue;
                }

                // Check execution time regression
                $baselineTime = $baselineResult['execution_time_ms'] ?? 0;
                $currentTime = $result['execution_time_ms'] ?? 0;
                if (!is_numeric($baselineTime) || !is_numeric($currentTime)) {
                    continue;
                }
                $timeRegression = $this->calculateRegression(
                    (float)$baselineTime,
                    (float)$currentTime
                );
                
                if ($timeRegression > $this->regressionThresholds['execution_time']) {
                    $regressions[] = sprintf(
                        "%s::%s execution time increased by %.1f%% (%.1fms → %.1fms)",
                        (string)$suiteName,
                        (string)$testName,
                        (float)$timeRegression,
                        (float)$baselineTime,
                        (float)$currentTime
                    );
                }

                // Check memory usage regression
                $baselineMemory = $baselineResult['memory_usage_bytes'] ?? 0;
                $currentMemory = $result['memory_usage_bytes'] ?? 0;
                if (!is_numeric($baselineMemory) || !is_numeric($currentMemory)) {
                    continue;
                }
                $memoryRegression = $this->calculateRegression(
                    (float)$baselineMemory,
                    (float)$currentMemory
                );
                
                if ($memoryRegression > $this->regressionThresholds['memory_usage']) {
                    $regressions[] = sprintf(
                        "%s::%s memory usage increased by %.1f%% (%.2fMB → %.2fMB)",
                        (string)$suiteName,
                        (string)$testName,
                        (float)$memoryRegression,
                        (float)($baselineMemory / 1024 / 1024),
                        (float)($currentMemory / 1024 / 1024)
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
        if ($baseline == 0) {
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
        $reportPath = isset($argv[1]) && is_string($argv[1]) ? $argv[1] : null;
        $runner = new self($reportPath);
        
        try {
            $runner->runAllBenchmarks();
            exit(0);
        } catch (\Exception $e) {
            echo "❌ Benchmark execution failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Allow running from command line
if (PHP_SAPI === 'cli' && isset($argv) && basename(__FILE__) === basename($argv[0])) {
    PerformanceBenchmarkRunner::main($argv);
}