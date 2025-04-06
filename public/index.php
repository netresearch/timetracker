<?php

use App\Kernel;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Dotenv\Dotenv;

/* @var Composer\Autoload\ClassLoader */
require dirname(__DIR__).'/vendor/autoload.php';

// Load cached env vars if the .env.local.php file exists
// Run "composer dump-env prod" to create it (requires symfony/flex >=1.2)
if (is_array($env = @include dirname(__DIR__).'/.env.local.php') && (!isset($env['APP_ENV']) || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? $env['APP_ENV']) === $env['APP_ENV'])) {
    (new Dotenv(false))->populate($env);
} else {
    // load all the .env files
    (new Dotenv(false))->loadEnv(dirname(__DIR__).'/.env');
}

$env = $_SERVER['APP_ENV'] ?? 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? ('prod' !== $env));

if ($debug) {
    umask(0000);
    Debug::enable();
}

$kernel = new Kernel($env, $debug);
$request = Request::createFromGlobals();

# feat #28: trust a defined list of proxy
if (!empty($_SERVER['TRUSTED_PROXY_LIST']) && null !== json_decode($_SERVER['TRUSTED_PROXY_LIST'])) {
    Request::setTrustedProxies(
        json_decode($_SERVER['TRUSTED_PROXY_LIST']),
        Request::HEADER_X_FORWARDED_ALL
    );
}

# feat #28: trust all remote addresses
if (!empty($_SERVER['TRUSTED_PROXY_ALL']) && true === (bool) $_SERVER['TRUSTED_PROXY_ALL']) {
    Request::setTrustedProxies(
        ['127.0.0.1', $request->server->get('REMOTE_ADDR')],
        Request::HEADER_X_FORWARDED_ALL
    );
}

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
