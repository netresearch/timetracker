<?php

declare(strict_types=1);

/**
 * E2E Coverage Collector
 *
 * This file collects PHP code coverage during E2E tests.
 * Include this at the start of index.php when COVERAGE_ENABLED=1.
 *
 * Usage:
 *   1. Set COVERAGE_ENABLED=1 and XDEBUG_MODE=coverage environment variables
 *   2. Run E2E tests
 *   3. Call GET /coverage.php?action=report to get coverage data
 *   4. Call GET /coverage.php?action=clear to reset coverage
 *
 * Security: Only active when COVERAGE_ENABLED=1 environment variable is set.
 *
 * @see https://xdebug.org/docs/code_coverage
 */

// Coverage storage directory
define('COVERAGE_DIR', dirname(__DIR__) . '/var/coverage/e2e');

/**
 * Check if coverage is enabled via environment variable.
 */
function isCoverageEnabled(): bool
{
    return !empty($_SERVER['COVERAGE_ENABLED']) || !empty($_ENV['COVERAGE_ENABLED']);
}

/**
 * Check if this is a direct request to coverage.php (handles nginx routing).
 */
function isDirectCoverageRequest(): bool
{
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    return str_starts_with(parse_url($requestUri, PHP_URL_PATH) ?? '', '/coverage.php');
}

// Handle coverage API requests (direct access to /coverage.php)
if (isDirectCoverageRequest()) {
    if (!isCoverageEnabled()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Coverage not enabled. Set COVERAGE_ENABLED=1']);
        exit;
    }
    handleCoverageRequest();
    exit;
}

/**
 * Start coverage collection for this request.
 */
function startCoverageCollection(): void
{
    if (!function_exists('xdebug_start_code_coverage')) {
        return;
    }

    if (!is_dir(COVERAGE_DIR)) {
        if (!@mkdir(COVERAGE_DIR, 0755, true) && !is_dir(COVERAGE_DIR)) {
            error_log('Coverage: Failed to create directory ' . COVERAGE_DIR);
            return;
        }
    }

    // Start collecting coverage with dead code analysis
    xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

    // Register shutdown function to save coverage
    register_shutdown_function('saveCoverageData');
}

/**
 * Save coverage data at the end of the request.
 */
function saveCoverageData(): void
{
    if (!function_exists('xdebug_get_code_coverage')) {
        return;
    }

    $coverage = xdebug_get_code_coverage();
    xdebug_stop_code_coverage();

    if (empty($coverage)) {
        return;
    }

    // Filter out dead code (-2) and non-src files before saving
    $filteredCoverage = [];
    foreach ($coverage as $file => $lines) {
        if (!str_contains($file, '/src/')) {
            continue;
        }
        $filteredLines = array_filter($lines, fn($hits) => $hits !== -2);
        if (!empty($filteredLines)) {
            $filteredCoverage[$file] = $filteredLines;
        }
    }

    if (empty($filteredCoverage)) {
        return;
    }

    // Generate unique filename using random bytes for better uniqueness
    $uniqueId = bin2hex(random_bytes(16));
    $filename = COVERAGE_DIR . '/coverage_' . $uniqueId . '.json';

    $result = @file_put_contents($filename, json_encode($filteredCoverage, JSON_THROW_ON_ERROR));
    if ($result === false) {
        error_log('Coverage: Failed to write coverage file ' . $filename);
    }
}

/**
 * Handle coverage API requests.
 */
function handleCoverageRequest(): void
{
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? 'status';

    // Validate action parameter
    if (!in_array($action, ['status', 'report', 'clear'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action. Use: status, report, clear']);
        return;
    }

    switch ($action) {
        case 'status':
            $files = is_dir(COVERAGE_DIR) ? (glob(COVERAGE_DIR . '/*.json') ?: []) : [];
            echo json_encode([
                'enabled' => function_exists('xdebug_start_code_coverage'),
                'xdebug_mode' => ini_get('xdebug.mode'),
                'coverage_dir' => COVERAGE_DIR,
                'files' => count($files),
            ]);
            break;

        case 'report':
            $format = $_GET['format'] ?? 'clover';
            if (!in_array($format, ['clover', 'json'], true)) {
                $format = 'clover';
            }
            generateCoverageReport($format);
            break;

        case 'clear':
            clearCoverageData();
            echo json_encode(['status' => 'cleared']);
            break;
    }
}

/**
 * Generate coverage report from collected data.
 */
function generateCoverageReport(string $format): void
{
    if (!is_dir(COVERAGE_DIR)) {
        http_response_code(404);
        echo json_encode(['error' => 'No coverage data found']);
        return;
    }

    // Merge all coverage files
    $mergedCoverage = [];
    $files = glob(COVERAGE_DIR . '/*.json') ?: [];

    foreach ($files as $file) {
        $content = @file_get_contents($file);
        if ($content === false) {
            error_log('Coverage: Failed to read file ' . $file);
            continue;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            error_log('Coverage: Invalid JSON in file ' . $file);
            continue;
        }

        foreach ($data as $filename => $lines) {
            if (!isset($mergedCoverage[$filename])) {
                $mergedCoverage[$filename] = [];
            }
            foreach ($lines as $line => $hits) {
                // Skip dead code
                if ($hits === -2) {
                    continue;
                }

                if (!isset($mergedCoverage[$filename][$line])) {
                    $mergedCoverage[$filename][$line] = 0;
                }

                // Xdebug: 1 = executed, -1 = not executed but executable
                if ($hits === 1) {
                    $mergedCoverage[$filename][$line] = 1;
                } elseif ($mergedCoverage[$filename][$line] !== 1 && $hits === -1) {
                    $mergedCoverage[$filename][$line] = -1;
                }
            }
        }
    }

    if ($format === 'clover') {
        header('Content-Type: application/xml');
        echo generateCloverXml($mergedCoverage);
    } else {
        echo json_encode([
            'files' => count($mergedCoverage),
            'coverage' => $mergedCoverage,
        ]);
    }
}

/**
 * Generate Clover XML format coverage report.
 */
function generateCloverXml(array $coverage): string
{
    $timestamp = time();
    $xml = new XMLWriter();
    $xml->openMemory();
    $xml->setIndent(true);
    $xml->startDocument('1.0', 'UTF-8');

    $xml->startElement('coverage');
    $xml->writeAttribute('generated', (string) $timestamp);

    $xml->startElement('project');
    $xml->writeAttribute('timestamp', (string) $timestamp);
    $xml->writeAttribute('name', 'timetracker-e2e');

    $totalStatements = 0;
    $coveredStatements = 0;

    foreach ($coverage as $filename => $lines) {
        $xml->startElement('file');
        $xml->writeAttribute('name', $filename);

        $fileStatements = 0;
        $fileCovered = 0;

        foreach ($lines as $line => $hits) {
            $xml->startElement('line');
            $xml->writeAttribute('num', (string) $line);
            $xml->writeAttribute('type', 'stmt');
            $xml->writeAttribute('count', $hits === 1 ? '1' : '0');
            $xml->endElement();

            $fileStatements++;
            if ($hits === 1) {
                $fileCovered++;
            }
        }

        $xml->startElement('metrics');
        $xml->writeAttribute('statements', (string) $fileStatements);
        $xml->writeAttribute('coveredstatements', (string) $fileCovered);
        $xml->endElement();

        $xml->endElement(); // file

        $totalStatements += $fileStatements;
        $coveredStatements += $fileCovered;
    }

    $xml->startElement('metrics');
    $xml->writeAttribute('statements', (string) $totalStatements);
    $xml->writeAttribute('coveredstatements', (string) $coveredStatements);
    $xml->writeAttribute('files', (string) count($coverage));
    $xml->endElement();

    $xml->endElement(); // project
    $xml->endElement(); // coverage

    return $xml->outputMemory();
}

/**
 * Clear all coverage data.
 */
function clearCoverageData(): void
{
    if (!is_dir(COVERAGE_DIR)) {
        return;
    }

    $files = glob(COVERAGE_DIR . '/*.json') ?: [];
    foreach ($files as $file) {
        if (!@unlink($file)) {
            error_log('Coverage: Failed to delete file ' . $file);
        }
    }
}

// Auto-start coverage if this file is included and coverage is enabled
if (!isDirectCoverageRequest() && isCoverageEnabled()) {
    startCoverageCollection();
}
