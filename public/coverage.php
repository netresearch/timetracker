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
 * @see https://xdebug.org/docs/code_coverage
 */

// Coverage storage directory
define('COVERAGE_DIR', dirname(__DIR__) . '/var/coverage/e2e');

// Handle coverage API requests
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'coverage.php') {
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
        mkdir(COVERAGE_DIR, 0777, true);
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

    // Generate unique filename for this request
    $filename = COVERAGE_DIR . '/coverage_' . uniqid('', true) . '.json';
    file_put_contents($filename, json_encode($coverage, JSON_THROW_ON_ERROR));
}

/**
 * Handle coverage API requests.
 */
function handleCoverageRequest(): void
{
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? 'status';

    switch ($action) {
        case 'status':
            echo json_encode([
                'enabled' => function_exists('xdebug_start_code_coverage'),
                'xdebug_mode' => ini_get('xdebug.mode'),
                'coverage_dir' => COVERAGE_DIR,
                'files' => is_dir(COVERAGE_DIR) ? count(glob(COVERAGE_DIR . '/*.json')) : 0,
            ]);
            break;

        case 'report':
            $format = $_GET['format'] ?? 'clover';
            generateCoverageReport($format);
            break;

        case 'clear':
            clearCoverageData();
            echo json_encode(['status' => 'cleared']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action: ' . $action]);
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
    $files = glob(COVERAGE_DIR . '/*.json');

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) {
            continue;
        }

        foreach ($data as $filename => $lines) {
            if (!isset($mergedCoverage[$filename])) {
                $mergedCoverage[$filename] = [];
            }
            foreach ($lines as $line => $hits) {
                if (!isset($mergedCoverage[$filename][$line])) {
                    $mergedCoverage[$filename][$line] = 0;
                }
                // Xdebug uses 1 for executed, -1 for not executed but executable, -2 for dead code
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

    // Filter to only include src/ files
    $srcCoverage = array_filter($coverage, function ($file) {
        return str_contains($file, '/src/');
    }, ARRAY_FILTER_USE_KEY);

    foreach ($srcCoverage as $filename => $lines) {
        $xml->startElement('file');
        $xml->writeAttribute('name', $filename);

        $fileStatements = 0;
        $fileCovered = 0;

        foreach ($lines as $line => $hits) {
            if ($hits === -2) {
                continue; // Skip dead code
            }

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
    $xml->writeAttribute('files', (string) count($srcCoverage));
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

    $files = glob(COVERAGE_DIR . '/*.json');
    foreach ($files as $file) {
        unlink($file);
    }
}

// Auto-start coverage if this file is included (not directly accessed)
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== 'coverage.php') {
    startCoverageCollection();
}
