#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Xdebug Test Script.
 *
 * Tests Xdebug installation and configuration in the development container.
 * Run with: docker-compose exec app-dev php bin/test-xdebug.php
 */
echo "Xdebug Test Script\n";
echo "==================\n\n";

// Check if Xdebug extension is loaded
echo "1. Checking Xdebug Extension:\n";
if (extension_loaded('xdebug')) {
    echo "   ✅ Xdebug extension is loaded\n";
    echo '   Version: ' . phpversion('xdebug') . "\n";
} else {
    echo "   ❌ Xdebug extension is NOT loaded\n";
    exit(1);
}

echo "\n2. Checking Xdebug Configuration:\n";

// Check Xdebug mode
$mode = ini_get('xdebug.mode');
echo '   xdebug.mode: ' . ($mode ?: 'not set') . "\n";

// Check client configuration
$clientHost = ini_get('xdebug.client_host');
$clientPort = ini_get('xdebug.client_port');
echo '   xdebug.client_host: ' . ($clientHost ?: 'not set') . "\n";
echo '   xdebug.client_port: ' . ($clientPort ?: 'not set') . "\n";

// Check discovery
$discover = ini_get('xdebug.discover_client_host');
echo '   xdebug.discover_client_host: ' . ($discover ? 'enabled' : 'disabled') . "\n";

echo "\n3. Xdebug Status:\n";
if (function_exists('xdebug_info')) {
    echo "   ✅ Xdebug functions are available\n";

    // Check if debugging is active
    if (function_exists('xdebug_is_debugger_active')) {
        $debuggerActive = xdebug_is_debugger_active();
        echo '   Debugger active: ' . ($debuggerActive ? 'yes' : 'no') . "\n";
    }

    // Show coverage capability
    if (function_exists('xdebug_start_code_coverage')) {
        echo "   ✅ Code coverage capability available\n";
    }
} else {
    echo "   ❌ Xdebug functions are not available\n";
}

echo "\n4. IDE Connection Test:\n";
$ideKey = getenv('XDEBUG_SESSION') ?: 'PHPSTORM';
echo '   IDE Key: ' . $ideKey . "\n";
echo "   To test debugging:\n";
echo "   - Set a breakpoint in your IDE\n";
echo "   - Add XDEBUG_SESSION=PHPSTORM to your browser or request\n";
echo "   - Or use: docker-compose exec app-dev php -dxdebug.start_with_request=yes script.php\n";

echo "\n5. Sample Debugging Function:\n";
function testBreakpoint(): string
{
    $variable = 'This is a test variable';
    $array = ['key1' => 'value1', 'key2' => 'value2'];

    // This line is good for setting a breakpoint
    echo "   Set a breakpoint on this line to test debugging\n";

    return $variable . ' - processed';
}

$result = testBreakpoint();
echo '   Function result: ' . $result . "\n";

echo "\n✅ Xdebug test completed successfully!\n";
echo "\nXdebug is ready for development debugging.\n";
echo "Configure your IDE to connect to host.docker.internal:9003\n";
