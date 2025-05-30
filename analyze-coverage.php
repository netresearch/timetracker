<?php

declare(strict_types=1);

// Configuration
$controllerDir = __DIR__ . '/src/Controller';
$testDir = __DIR__ . '/tests/Controller';

echo "Analyzing controller test coverage...\n\n";

// Get all controller files
$controllerFiles = glob($controllerDir . '/*.php');
$untested = [];
$total = 0;
$tested = 0;

foreach ($controllerFiles as $controllerFile) {
    $fileName = basename($controllerFile);
    $className = basename($controllerFile, '.php');
    $testFile = $testDir . '/' . $className . 'Test.php';

    // Skip BaseController as it's abstract/extended
    if ($className === 'BaseController') {
        continue;
    }

    echo "Analyzing $className...\n";

    // Parse the controller file to extract public methods
    $content = file_get_contents($controllerFile);
    preg_match_all('/public\s+function\s+(\w+)Action\s*\(/i', $content, $matches);
    $controllerMethods = $matches[1] ?? [];

    // Parse the test file to extract test methods
    $testMethods = [];
    if (file_exists($testFile)) {
        $testContent = file_get_contents($testFile);
        preg_match_all('/public\s+function\s+test(\w+)(?:Action)?\s*\(/i', $testContent, $testMatches);
        $testMethods = array_map('strtolower', $testMatches[1] ?? []);
    }

    // Compare to find untested methods
    $untestedMethods = [];
    foreach ($controllerMethods as $method) {
        $total++;
        $methodLower = strtolower($method);
        if (!in_array($methodLower, $testMethods)) {
            $untestedMethods[] = $method;
        } else {
            $tested++;
        }
    }

    if (!empty($untestedMethods)) {
        $untested[$className] = $untestedMethods;
        echo "  Untested methods: " . implode(', ', $untestedMethods) . "\n";
    } else {
        echo "  All methods are tested!\n";
    }
}

echo "\nSummary:\n";
echo "Total controller action methods: $total\n";
echo "Tested methods: $tested (" . round(($tested / $total) * 100, 2) . "%)\n";
echo "Untested methods: " . ($total - $tested) . " (" . round((($total - $tested) / $total) * 100, 2) . "%)\n\n";

if (!empty($untested)) {
    echo "Untested methods by controller:\n";
    foreach ($untested as $controller => $methods) {
        echo "$controller: " . implode(', ', $methods) . "\n";
    }
}
