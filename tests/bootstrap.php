<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Integrate Symfony PHPUnit Bridge for compatibility with PHPUnit 12
// This ensures Symfony's error/exception handlers are properly managed in tests
if (file_exists(dirname(__DIR__).'/vendor/symfony/phpunit-bridge/bootstrap.php')) {
    require dirname(__DIR__).'/vendor/symfony/phpunit-bridge/bootstrap.php';
}

// Load cached env vars if the .env.local.php file exists
if (is_array($env = @include dirname(__DIR__).'/.env.local.php') && (!isset($env['APP_ENV']) || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? $env['APP_ENV']) === $env['APP_ENV'])) {
    (new Dotenv())->usePutenv(false)->populate($env);
} else {
    // load all the .env files
    (new Dotenv())->usePutenv(false)->loadEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
