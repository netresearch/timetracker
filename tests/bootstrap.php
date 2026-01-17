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

// Set up environment for tests
// Respect APP_ENV from PHPUnit's phpunit.xml.dist (via $_SERVER)
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] ?? 'test';

// Load .env files with proper test environment precedence
(new Dotenv())->usePutenv(false)->bootEnv(dirname(__DIR__) . '/.env');

if (isset($_SERVER['APP_DEBUG']) && (bool) $_SERVER['APP_DEBUG']) {
    umask(0o000);
}
