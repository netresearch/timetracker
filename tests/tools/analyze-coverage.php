<?php

declare(strict_types=1);

/**
 * Test Coverage Analysis Script.
 *
 * Analyzes controller files to identify untested public action methods.
 * Compares controller actions with existing test methods to find coverage gaps.
 */

// Load Composer autoloader if available
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

// Suppress warnings about unused use statements in global scope
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RegexIterator;
use Throwable;

final readonly class TestCoverageAnalyzer
{
    public function __construct(
        private string $controllersPath = __DIR__ . '/src/Controller',
        private string $testsPath = __DIR__ . '/tests/Controller',
    ) {
    }

    /**
     * @return array{summary: array<string, mixed>, untested: array<int, array<string, mixed>>, tested: array<int, array<string, mixed>>}
     */
    public function analyze(): array
    {
        $controllers = $this->findControllers();
        $tests = $this->findTestMethods();

        $results = [
            'summary' => [
                'total_controllers' => count($controllers),
                'total_tests' => count($tests),
                'untested_actions' => 0,
                'coverage_percentage' => 0.0,
            ],
            'untested' => [],
            'tested' => [],
        ];

        $totalActions = 0;
        $testedActions = 0;

        foreach ($controllers as $controllerClass => $actions) {
            foreach ($actions as $action) {
                ++$totalActions;
                $isTestedAction = $this->isActionTested($controllerClass, (string) $action, $tests);

                if ($isTestedAction) {
                    ++$testedActions;
                    $results['tested'][] = [
                        'controller' => $controllerClass,
                        'action' => $action,
                        'test_method' => $isTestedAction,
                    ];
                } else {
                    $results['untested'][] = [
                        'controller' => $controllerClass,
                        'action' => $action,
                        'file' => $this->getControllerFile($controllerClass),
                    ];
                }
            }
        }

        $results['summary']['untested_actions'] = count($results['untested']);
        $results['summary']['coverage_percentage'] = $totalActions > 0
            ? round(($testedActions / $totalActions) * 100, 2)
            : 0.0;

        return $results;
    }

    /**
     * Find all controller classes and their public action methods.
     */
    /**
     * @return array<string, array<int, string>>
     */
    private function findControllers(): array
    {
        $controllers = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->controllersPath),
        );
        $phpFiles = new RegexIterator($iterator, '/\.php$/');

        foreach ($phpFiles as $file) {
            if ($file instanceof SplFileInfo) {
                $pathname = $file->getPathname();
                assert(is_string($pathname));
                $relativePath = str_replace($this->controllersPath . '/', '', $pathname);
                $className = $this->getClassNameFromFile($pathname);
            } else {
                continue;
            }

            if (null === $className || ! class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);

                // Skip abstract classes and base controllers
                if ($reflection->isAbstract() || 'App\Controller\BaseController' === $className) {
                    continue;
                }

                $actions = $this->extractPublicActions($reflection);
                if ([] !== $actions) {
                    $controllers[$className] = $actions;
                }
            } catch (ReflectionException $e) {
                echo "Warning: Could not analyze {$className}: {$e->getMessage()}\n";
            }
        }

        return $controllers;
    }

    /**
     * Extract public action methods from a controller.
     */
    /**
     * @return array<int, string>
     */
    private function extractPublicActions(ReflectionClass $reflection): array
    {
        $actions = [];
        $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            // Skip inherited methods from parent classes (except __invoke)
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()
                && '__invoke' !== $method->getName()) {
                continue;
            }

            // Skip magic methods (except __invoke), constructors, and setters
            $methodName = $method->getName();
            if ('__construct' === $methodName
                || str_starts_with($methodName, 'set')
                || (str_starts_with($methodName, '__') && '__invoke' !== $methodName)) {
                continue;
            }

            // Include methods that look like actions
            if ('__invoke' === $methodName
                || str_ends_with($methodName, 'Action')
                || $this->hasRouteAttribute($method)) {
                $actions[] = $methodName;
            }
        }

        return $actions;
    }

    /**
     * Check if a method has a Route attribute.
     */
    private function hasRouteAttribute(ReflectionMethod $method): bool
    {
        $attributes = $method->getAttributes();
        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();
            if (str_contains($attributeName, 'Route')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find all test methods in test files.
     */
    /**
     * @return array<string, array<int, string>>
     */
    private function findTestMethods(): array
    {
        $testMethods = [];

        if (! is_dir($this->testsPath)) {
            return $testMethods;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->testsPath),
        );
        $phpFiles = new RegexIterator($iterator, '/Test\.php$/');

        foreach ($phpFiles as $file) {
            if ($file instanceof SplFileInfo) {
                $pathname = $file->getPathname();
                assert(is_string($pathname));
                $className = $this->getClassNameFromFile($pathname, 'Tests');
            } else {
                continue;
            }

            if (null === $className || ! class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);
                $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

                foreach ($methods as $method) {
                    $methodName = $method->getName();
                    if (str_starts_with($methodName, 'test')) {
                        $testMethods[$className][] = $methodName;
                    }
                }
            } catch (ReflectionException $e) {
                echo "Warning: Could not analyze test {$className}: {$e->getMessage()}\n";
            }
        }

        return $testMethods;
    }

    /**
     * Check if an action is tested by matching patterns.
     */
    /**
     * @param array<string, array<int, string>> $tests
     */
    private function isActionTested(string $controllerClass, string $action, array $tests): string|false
    {
        // Extract controller area and action name for better matching
        $controllerInfo = $this->extractControllerInfo($controllerClass);

        foreach ($tests as $testClass => $testMethods) {
            // Match by controller area (Settings, Admin, Default, etc.)
            $testArea = $this->extractTestArea($testClass);

            assert(is_string($controllerInfo['area']));
            assert(is_string($testArea));
            if ($this->areasMatch($controllerInfo['area'], $testArea)) {
                foreach ($testMethods as $testMethod) {
                    assert(is_string($controllerInfo['action']));
                    assert(is_string($testMethod));
                    if ($this->matchesTestPattern($controllerInfo['action'], $action, $testMethod)) {
                        return "{$testClass}::{$testMethod}";
                    }
                }
            }
        }

        return false;
    }

    /**
     * Match action method with test method patterns.
     */
    private function matchesTestPattern(string $actionName, string $methodName, string $testMethod): bool
    {
        $normalizedAction = strtolower($actionName);
        $normalizedTest = strtolower(str_replace('test', '', $testMethod));

        // Direct action name match with high confidence (e.g., SaveSettingsAction -> testSaveAction, testSave)
        if ($normalizedTest === strtolower(str_replace('action', '', $normalizedAction))) {
            return true;
        }

        // Exact verb match (higher priority)
        $exactMatches = [
            'savesettings' => ['save', 'saveaction'],
            'saveentry' => ['save', 'saveaction'],
            'saveactivity' => ['save', 'saveaction'],
            'savecustomer' => ['save', 'saveaction'],
            'saveuser' => ['save', 'saveaction'],
            'saveproject' => ['save', 'saveaction'],
            'exportcsv' => ['export', 'exportaction'],
            'bulkentry' => ['bulk', 'bulkentry', 'bulkentryaction'],
            'deleteentry' => ['delete', 'deleteaction'],
            'deleteuser' => ['delete', 'deleteaction'],
            'indexaction' => ['index', 'indexaction'],
            'pageaction' => ['page', 'pageaction'],
        ];

        $key = strtolower(str_replace('action', '', $actionName));
        if (isset($exactMatches[$key])) {
            foreach ($exactMatches[$key] as $pattern) {
                if (str_contains($normalizedTest, $pattern)) {
                    return true;
                }
            }
        }

        // Interpretation controller specific patterns
        if (str_contains($actionName, 'GroupBy')) {
            $groupType = strtolower(str_replace(['GroupBy', 'Action'], '', $actionName));
            if (str_contains($normalizedTest, 'groupby' . $groupType)) {
                return true;
            }
        }

        // Common verb patterns with stricter matching
        $strictPatterns = [
            'get' => ['get', 'load', 'fetch'],
            'save' => ['save', 'create', 'post'],
            'delete' => ['delete', 'remove'],
            'export' => ['export'],
            'sync' => ['sync'],
        ];

        foreach ($strictPatterns as $verb => $testPatterns) {
            if (str_starts_with($normalizedAction, $verb)) {
                foreach ($testPatterns as $pattern) {
                    if (str_starts_with($normalizedTest, $pattern) || $normalizedTest === $pattern . 'action') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Extract controller area and action information.
     */
    /**
     * @return array<string, string>
     */
    private function extractControllerInfo(string $controllerClass): array
    {
        // App\Controller\Settings\SaveSettingsAction -> ['area' => 'Settings', 'action' => 'savesettings']
        $parts = explode('\\', $controllerClass);
        $className = end($parts);
        $area = count($parts) > 3 ? $parts[2] : 'Default'; // Extract area from namespace

        // Extract action name from class name
        $actionName = str_replace('Action', '', $className);
        $actionName = strtolower(preg_replace('/([a-z])([A-Z])/', '$1$2', $actionName) ?? '');

        return [
            'area' => $area,
            'action' => $actionName,
            'class' => $className,
        ];
    }

    /**
     * Extract test area from test class name.
     */
    private function extractTestArea(string $testClass): string
    {
        // Tests\Controller\SettingsControllerTest -> Settings
        if (1 === preg_match('/Tests\\\\Controller\\\\(\w+)ControllerTest/', $testClass, $matches)) {
            return $matches[1];
        }

        // Fallback patterns
        $parts = explode('\\', $testClass);
        $className = end($parts);

        if (str_contains($className, 'Settings')) {
            return 'Settings';
        }
        if (str_contains($className, 'Admin')) {
            return 'Admin';
        }
        if (str_contains($className, 'Default')) {
            return 'Default';
        }
        if (str_contains($className, 'Controlling')) {
            return 'Controlling';
        }
        if (str_contains($className, 'Tracking') || str_contains($className, 'Crud')) {
            return 'Tracking';
        }
        if (str_contains($className, 'Interpretation')) {
            return 'Interpretation';
        }
        if (str_contains($className, 'Status')) {
            return 'Status';
        }
        if (str_contains($className, 'Security')) {
            return 'Security';
        }

        return 'Default';
    }

    /**
     * Check if controller area matches test area.
     */
    private function areasMatch(string $controllerArea, string $testArea): bool
    {
        // Direct match
        if ($controllerArea === $testArea) {
            return true;
        }

        // Special mappings
        $mappings = [
            'Tracking' => ['Crud'],
            'Default' => ['Security'], // Some default actions might be tested in security tests
        ];

        if (isset($mappings[$controllerArea])) {
            return in_array($testArea, $mappings[$controllerArea], true);
        }

        if (isset($mappings[$testArea])) {
            return in_array($controllerArea, $mappings[$testArea], true);
        }

        return false;
    }

    /**
     * Extract class name from file path.
     */
    private function getClassNameFromFile(string $filePath, string $namespace = 'App'): ?string
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            return null;
        }

        // Extract namespace and class name
        preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches);
        preg_match('/class\s+(\w+)/', $content, $classMatches);

        if (! isset($namespaceMatches[1], $classMatches[1])) {
            return null;
        }

        return $namespaceMatches[1] . '\\' . $classMatches[1];
    }

    /**
     * Get the file path for a controller class.
     */
    private function getControllerFile(string $controllerClass): string
    {
        $relativePath = str_replace(['App\\Controller\\', '\\'], ['', '/'], $controllerClass);

        return "src/Controller/{$relativePath}.php";
    }
}

/**
 * Output formatter for analysis results.
 */
final readonly class OutputFormatter
{
    /**
     * @param array{summary: array<string, mixed>, untested: array<int, array<string, mixed>>, tested: array<int, array<string, mixed>>} $results
     */
    public function formatResults(array $results): void
    {
        $this->printHeader();
        $this->printSummary($results['summary']);

        if ([] !== $results['untested']) {
            $this->printUntestedActions($results['untested']);
        }

        if ([] !== $results['tested']) {
            $this->printTestedActions($results['tested']);
        }
    }

    private function printHeader(): void
    {
        echo "\n" . str_repeat('=', 80) . "\n";
        echo "                    TEST COVERAGE ANALYSIS\n";
        echo str_repeat('=', 80) . "\n";
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function printSummary(array $summary): void
    {
        echo "\n📊 SUMMARY:\n";
        echo str_repeat('-', 40) . "\n";
        assert(is_int($summary['total_controllers']));
        assert(is_int($summary['total_tests']));
        assert(is_int($summary['untested_actions']));
        assert(is_float($summary['coverage_percentage']));
        echo sprintf("Total Controllers: %d\n", $summary['total_controllers']);
        echo sprintf("Total Test Classes: %d\n", $summary['total_tests']);
        echo sprintf("Untested Actions: %d\n", $summary['untested_actions']);
        echo sprintf("Coverage: %.2f%%\n", $summary['coverage_percentage']);

        $coverageBar = $this->generateCoverageBar($summary['coverage_percentage']);
        echo "Progress: {$coverageBar}\n";
    }

    /**
     * @param array<int, array<string, mixed>> $untested
     */
    private function printUntestedActions(array $untested): void
    {
        echo "\n❌ UNTESTED CONTROLLER ACTIONS:\n";
        echo str_repeat('-', 60) . "\n";

        $groupedByController = [];
        foreach ($untested as $item) {
            if (is_array($item) && isset($item['controller'])) {
                $controller = $item['controller'];
                if (is_string($controller)) {
                    $groupedByController[$controller][] = $item;
                }
            }
        }

        foreach ($groupedByController as $controller => $actions) {
            echo "\n🎯 {$controller}:\n";
            foreach ($actions as $action) {
                $actionName = is_scalar($action['action']) ? (string) $action['action'] : 'unknown';
                $fileName = is_scalar($action['file']) ? (string) $action['file'] : 'unknown';
                echo "   • {$actionName}()\n";
                echo "     📁 {$fileName}\n";
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $tested
     */
    private function printTestedActions(array $tested): void
    {
        if ([] === $tested) {
            return;
        }

        echo "\n✅ TESTED CONTROLLER ACTIONS:\n";
        echo str_repeat('-', 60) . "\n";

        foreach ($tested as $item) {
            $controller = is_scalar($item['controller']) ? (string) $item['controller'] : 'unknown';
            $action = is_scalar($item['action']) ? (string) $item['action'] : 'unknown';
            $testMethod = is_scalar($item['test_method']) ? (string) $item['test_method'] : 'unknown';
            echo sprintf(
                "• %s::%s() → %s\n",
                basename(str_replace('\\', '/', $controller)),
                $action,
                $testMethod,
            );
        }
    }

    private function generateCoverageBar(float $percentage): string
    {
        $width = 30;
        $filled = (int) round(($percentage / 100) * $width);
        $empty = $width - $filled;

        $bar = '[' . str_repeat('█', $filled) . str_repeat('░', $empty) . ']';

        return sprintf('%s %.1f%%', $bar, $percentage);
    }
}

// Main execution
if (\PHP_SAPI === 'cli') {
    // Check for help argument
    if (in_array('--help', $argv ?? [], true) || in_array('-h', $argv ?? [], true)) {
        echo <<<'HELP'

            Test Coverage Analysis Script
            ============================

            Usage: php analyze-coverage.php [options]
               or: docker compose run --rm app php analyze-coverage.php [options]

            Options:
              --help, -h    Show this help message

            Description:
              Analyzes controller files to identify untested public action methods.
              Scans src/Controller/ for PHP controllers and checks if corresponding
              test methods exist in tests/Controller/.

            Exit Codes:
              0 = All controllers have test coverage
              1 = Some controllers lack test coverage
              2 = Analysis error occurred

            HELP;
        exit(0);
    }

    try {
        echo "🔍 Analyzing test coverage...\n";

        $analyzer = new TestCoverageAnalyzer();
        $results = $analyzer->analyze();

        $formatter = new OutputFormatter();
        $formatter->formatResults($results);

        echo "\n" . str_repeat('=', 80) . "\n";
        echo "Analysis complete! Use this report to identify testing gaps.\n";

        assert(is_array($results['summary']) && isset($results['summary']['untested_actions']));
        if ($results['summary']['untested_actions'] > 0) {
            echo "💡 Tip: Consider adding tests for untested controller actions above.\n";
        } else {
            echo "🎉 Excellent! All controller actions have corresponding tests.\n";
        }

        echo str_repeat('=', 80) . "\n\n";

        // Exit with appropriate code
        $exitCode = $results['summary']['untested_actions'] > 0 ? 1 : 0;
        exit($exitCode);

    } catch (Throwable $e) {
        echo '❌ Error during analysis: ' . $e->getMessage() . "\n";
        if (isset($argv) && in_array('--verbose', $argv, true)) {
            echo 'Stack trace: ' . $e->getTraceAsString() . "\n";
        }
        exit(2);
    }
}
