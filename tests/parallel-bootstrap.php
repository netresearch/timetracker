<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

// Force load mbstring polyfill for PHPUnit compatibility
if (!extension_loaded('mbstring')) {
    require dirname(__DIR__) . '/vendor/symfony/polyfill-mbstring/bootstrap.php';
}

// Integrate Symfony PHPUnit Bridge for compatibility with PHPUnit 12
// This ensures Symfony's error/exception handlers are properly managed in tests
if (file_exists(dirname(__DIR__) . '/vendor/symfony/phpunit-bridge/bootstrap.php')) {
    require dirname(__DIR__) . '/vendor/symfony/phpunit-bridge/bootstrap.php';
}

// Load cached env vars if the .env.local.php file exists
if (is_array($env = @include dirname(__DIR__) . '/.env.local.php') && (!isset($env['APP_ENV']) || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? $env['APP_ENV']) === $env['APP_ENV'])) {
    (new Dotenv())->usePutenv(false)->populate($env);
} else {
    // load all the .env files
    (new Dotenv())->usePutenv(false)->loadEnv(dirname(__DIR__) . '/.env');
}

if (isset($_SERVER['APP_DEBUG']) && $_SERVER['APP_DEBUG']) {
    umask(0o000);
}

// Parallel execution enhancements
if (getenv('PARATEST_PARALLEL') || isset($_ENV['PARATEST_PARALLEL'])) {
    // Ensure each process has a unique identifier for database isolation
    $processId = getenv('TEST_TOKEN') ?: uniqid('test_', true);
    $_ENV['TEST_PROCESS_ID'] = $processId;
    $_SERVER['TEST_PROCESS_ID'] = $processId;
    
    // Override database URL to include process ID for isolation
    if (isset($_ENV['DATABASE_URL'])) {
        // Extract database name and add process suffix
        $databaseUrl = $_ENV['DATABASE_URL'];
        if (preg_match('/\/([^?]+)(\?|$)/', $databaseUrl, $matches)) {
            $dbName = $matches[1];
            $newDbName = $dbName . '_' . substr(md5($processId), 0, 8);
            $_ENV['DATABASE_URL'] = str_replace('/' . $dbName, '/' . $newDbName, $databaseUrl);
            $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'];
        }
    }
    
    // Set process-specific cache directory
    $_ENV['CACHE_DIR'] = dirname(__DIR__) . '/var/cache/test_' . substr(md5($processId), 0, 8);
    $_SERVER['CACHE_DIR'] = $_ENV['CACHE_DIR'];
}