<?php

declare(strict_types=1);

namespace Tests\Performance;

use DateTime;

/**
 * Performance dashboard generator for export benchmarks.
 * 
 * Generates HTML dashboard with performance trends and metrics visualization.
 * 
 * @internal
 */
final class PerformanceDashboard
{
    private string $historyFile;
    private string $outputPath;

    public function __construct(string $historyFile = null, string $outputPath = null)
    {
        $this->historyFile = $historyFile ?? __DIR__ . '/../../var/performance-history.json';
        $this->outputPath = $outputPath ?? __DIR__ . '/../../var/performance-dashboard.html';
    }

    /**
     * Generate performance dashboard HTML.
     */
    public function generateDashboard(): string
    {
        $history = $this->loadPerformanceHistory();
        
        if (empty($history)) {
            return $this->generateEmptyDashboard();
        }

        $html = $this->generateDashboardHtml($history);
        
        // Ensure output directory exists
        $outputDir = dirname($this->outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }
        
        file_put_contents($this->outputPath, $html);
        
        return $this->outputPath;
    }

    /**
     * Load performance history from JSON file.
     */
    private function loadPerformanceHistory(): array
    {
        if (!file_exists($this->historyFile)) {
            return [];
        }

        $content = file_get_contents($this->historyFile);
        if ($content === false) {
            return [];
        }

        $history = json_decode($content, true);
        return is_array($history) ? $history : [];
    }

    /**
     * Generate dashboard HTML with performance trends.
     */
    private function generateDashboardHtml(array $history): string
    {
        $latest = end($history);
        $trends = $this->calculateTrends($history);
        $chartData = $this->prepareChartData($history);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Performance Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            margin: 20px; 
            background-color: #f5f5f5; 
        }
        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            background: white; 
            padding: 20px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            border-bottom: 2px solid #e0e0e0; 
            padding-bottom: 20px; 
        }
        .metrics { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px; 
            margin-bottom: 30px; 
        }
        .metric-card { 
            background: #f8f9fa; 
            padding: 20px; 
            border-radius: 6px; 
            border-left: 4px solid #007bff; 
        }
        .metric-title { 
            font-weight: bold; 
            margin-bottom: 10px; 
            color: #495057; 
        }
        .metric-value { 
            font-size: 24px; 
            font-weight: bold; 
            color: #007bff; 
        }
        .metric-trend { 
            font-size: 14px; 
            margin-top: 5px; 
        }
        .trend-up { color: #dc3545; }
        .trend-down { color: #28a745; }
        .trend-stable { color: #6c757d; }
        .charts { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 30px; 
            margin-bottom: 30px; 
        }
        .chart-container { 
            background: white; 
            padding: 20px; 
            border-radius: 6px; 
            border: 1px solid #e0e0e0; 
        }
        .chart-title { 
            font-weight: bold; 
            margin-bottom: 15px; 
            text-align: center; 
        }
        .test-results { 
            margin-top: 30px; 
        }
        .test-table { 
            width: 100%; 
            border-collapse: collapse; 
        }
        .test-table th, .test-table td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #e0e0e0; 
        }
        .test-table th { 
            background-color: #f8f9fa; 
            font-weight: bold; 
        }
        .status-success { color: #28a745; }
        .status-failure { color: #dc3545; }
        .footer { 
            text-align: center; 
            margin-top: 30px; 
            padding-top: 20px; 
            border-top: 1px solid #e0e0e0; 
            color: #6c757d; 
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Export Performance Dashboard</h1>
            <p>Last Updated: {$latest['timestamp']}</p>
            <p>PHP Version: {$latest['php_version']} | Memory Limit: {$latest['memory_limit']}</p>
        </div>

        <div class="metrics">
            {$this->generateMetricsHtml($latest, $trends)}
        </div>

        <div class="charts">
            <div class="chart-container">
                <div class="chart-title">Execution Time Trends</div>
                <canvas id="executionTimeChart"></canvas>
            </div>
            <div class="chart-container">
                <div class="chart-title">Memory Usage Trends</div>
                <canvas id="memoryUsageChart"></canvas>
            </div>
        </div>

        <div class="test-results">
            <h2>Latest Test Results</h2>
            {$this->generateTestResultsHtml($latest)}
        </div>

        <div class="footer">
            <p>Generated by Export Performance Benchmark Suite</p>
        </div>
    </div>

    <script>
        {$this->generateChartJavaScript($chartData)}
    </script>
</body>
</html>
HTML;
    }

    /**
     * Generate empty dashboard when no history is available.
     */
    private function generateEmptyDashboard(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Performance Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; text-align: center; }
        .container { max-width: 600px; margin: 100px auto; padding: 40px; background: #f8f9fa; border-radius: 8px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Export Performance Dashboard</h1>
        <p>No performance history available.</p>
        <p>Run <code>composer perf:benchmark</code> to generate performance data.</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate metrics HTML cards.
     */
    private function generateMetricsHtml(array $latest, array $trends): string
    {
        $metrics = [];
        
        // Calculate average execution times
        $avgTimes = $this->calculateAverageExecutionTimes($latest);
        
        foreach ($avgTimes as $suite => $avgTime) {
            $trend = $trends[$suite]['execution_time'] ?? 0;
            $trendClass = $this->getTrendClass($trend);
            $trendSymbol = $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→');
            
            $metrics[] = <<<HTML
                <div class="metric-card">
                    <div class="metric-title">{$suite} Avg Execution Time</div>
                    <div class="metric-value">{$avgTime}ms</div>
                    <div class="metric-trend {$trendClass}">{$trendSymbol} {$trend}%</div>
                </div>
HTML;
        }

        // Memory usage metric
        $avgMemory = $this->calculateAverageMemoryUsage($latest);
        $memoryTrend = $trends['overall']['memory_usage'] ?? 0;
        $memoryTrendClass = $this->getTrendClass($memoryTrend);
        $memoryTrendSymbol = $memoryTrend > 0 ? '↑' : ($memoryTrend < 0 ? '↓' : '→');
        
        $metrics[] = <<<HTML
            <div class="metric-card">
                <div class="metric-title">Average Memory Usage</div>
                <div class="metric-value">{$avgMemory}MB</div>
                <div class="metric-trend {$memoryTrendClass}">{$memoryTrendSymbol} {$memoryTrend}%</div>
            </div>
HTML;

        return implode("\n", $metrics);
    }

    /**
     * Generate test results table HTML.
     */
    private function generateTestResultsHtml(array $latest): string
    {
        $html = '<table class="test-table"><thead><tr><th>Test Suite</th><th>Test Name</th><th>Status</th><th>Duration</th><th>Memory</th></tr></thead><tbody>';
        
        foreach ($latest['benchmarks'] as $suiteName => $suite) {
            foreach ($suite as $testName => $result) {
                $status = $result['success'] ? 'PASS' : 'FAIL';
                $statusClass = $result['success'] ? 'status-success' : 'status-failure';
                $duration = number_format($result['execution_time_ms'] ?? 0, 1);
                $memory = number_format(($result['memory_usage_bytes'] ?? 0) / 1024 / 1024, 2);
                
                $html .= <<<HTML
                    <tr>
                        <td>{$suiteName}</td>
                        <td>{$testName}</td>
                        <td><span class="{$statusClass}">{$status}</span></td>
                        <td>{$duration}ms</td>
                        <td>{$memory}MB</td>
                    </tr>
HTML;
            }
        }
        
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Calculate performance trends between runs.
     */
    private function calculateTrends(array $history): array
    {
        if (count($history) < 2) {
            return [];
        }

        $current = end($history);
        $previous = $history[count($history) - 2];
        
        $trends = [];
        
        foreach ($current['benchmarks'] as $suiteName => $suite) {
            $prevSuite = $previous['benchmarks'][$suiteName] ?? [];
            $trends[$suiteName] = [];
            
            $currentAvg = $this->calculateSuiteAverageTime($suite);
            $prevAvg = $this->calculateSuiteAverageTime($prevSuite);
            
            if ($prevAvg > 0) {
                $trends[$suiteName]['execution_time'] = round((($currentAvg - $prevAvg) / $prevAvg) * 100, 1);
            }
        }

        return $trends;
    }

    /**
     * Prepare data for JavaScript charts.
     */
    private function prepareChartData(array $history): array
    {
        $executionTimes = [];
        $memoryUsages = [];
        $timestamps = [];

        foreach ($history as $run) {
            $timestamps[] = (new DateTime($run['timestamp']))->format('M d');
            
            // Calculate average execution time per run
            $totalTime = 0;
            $totalTests = 0;
            $totalMemory = 0;
            
            foreach ($run['benchmarks'] as $suite) {
                foreach ($suite as $result) {
                    if ($result['success']) {
                        $totalTime += $result['execution_time_ms'] ?? 0;
                        $totalMemory += ($result['memory_usage_bytes'] ?? 0) / 1024 / 1024;
                        $totalTests++;
                    }
                }
            }
            
            $executionTimes[] = $totalTests > 0 ? round($totalTime / $totalTests, 1) : 0;
            $memoryUsages[] = $totalTests > 0 ? round($totalMemory / $totalTests, 2) : 0;
        }

        return [
            'timestamps' => $timestamps,
            'executionTimes' => $executionTimes,
            'memoryUsages' => $memoryUsages
        ];
    }

    /**
     * Generate JavaScript for charts.
     */
    private function generateChartJavaScript(array $chartData): string
    {
        $timestamps = json_encode($chartData['timestamps']);
        $executionTimes = json_encode($chartData['executionTimes']);
        $memoryUsages = json_encode($chartData['memoryUsages']);

        return <<<JAVASCRIPT
// Execution Time Chart
const executionTimeCtx = document.getElementById('executionTimeChart').getContext('2d');
new Chart(executionTimeCtx, {
    type: 'line',
    data: {
        labels: {$timestamps},
        datasets: [{
            label: 'Average Execution Time (ms)',
            data: {$executionTimes},
            borderColor: '#007bff',
            backgroundColor: 'rgba(0, 123, 255, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Memory Usage Chart
const memoryUsageCtx = document.getElementById('memoryUsageChart').getContext('2d');
new Chart(memoryUsageCtx, {
    type: 'line',
    data: {
        labels: {$timestamps},
        datasets: [{
            label: 'Average Memory Usage (MB)',
            data: {$memoryUsages},
            borderColor: '#28a745',
            backgroundColor: 'rgba(40, 167, 69, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
JAVASCRIPT;
    }

    /**
     * Calculate average execution times by suite.
     */
    private function calculateAverageExecutionTimes(array $run): array
    {
        $averages = [];
        
        foreach ($run['benchmarks'] as $suiteName => $suite) {
            $averages[$suiteName] = $this->calculateSuiteAverageTime($suite);
        }
        
        return $averages;
    }

    /**
     * Calculate average memory usage.
     */
    private function calculateAverageMemoryUsage(array $run): float
    {
        $totalMemory = 0;
        $totalTests = 0;
        
        foreach ($run['benchmarks'] as $suite) {
            foreach ($suite as $result) {
                if ($result['success']) {
                    $totalMemory += ($result['memory_usage_bytes'] ?? 0);
                    $totalTests++;
                }
            }
        }
        
        return $totalTests > 0 ? round($totalMemory / $totalTests / 1024 / 1024, 2) : 0;
    }

    /**
     * Calculate average execution time for a test suite.
     */
    private function calculateSuiteAverageTime(array $suite): float
    {
        $totalTime = 0;
        $successfulTests = 0;
        
        foreach ($suite as $result) {
            if ($result['success']) {
                $totalTime += $result['execution_time_ms'] ?? 0;
                $successfulTests++;
            }
        }
        
        return $successfulTests > 0 ? round($totalTime / $successfulTests, 1) : 0;
    }

    /**
     * Get CSS class for trend indicator.
     */
    private function getTrendClass(float $trend): string
    {
        if ($trend > 5) return 'trend-up';
        if ($trend < -5) return 'trend-down';
        return 'trend-stable';
    }

    /**
     * Main entry point for CLI usage.
     */
    public static function main(array $argv = []): void
    {
        $historyFile = $argv[1] ?? null;
        $outputPath = $argv[2] ?? null;
        
        $dashboard = new self($historyFile, $outputPath);
        
        try {
            $outputFile = $dashboard->generateDashboard();
            echo "✅ Performance dashboard generated: {$outputFile}\n";
            exit(0);
        } catch (\Exception $e) {
            echo "❌ Dashboard generation failed: " . $e->getMessage() . "\n";
            exit(1);
        }
    }
}

// Allow running from command line
if (PHP_SAPI === 'cli' && isset($argv) && basename(__FILE__) === basename($argv[0])) {
    PerformanceDashboard::main($argv);
}
JAVASCRIPT;
    }
}