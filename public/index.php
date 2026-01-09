<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

/* @var Composer\Autoload\ClassLoader */
require dirname(__DIR__) . '/vendor/autoload.php';

// Load cached env vars if the .env.local.php file exists
// Run "composer dump-env prod" to create it (requires symfony/flex >=1.2)
if (is_array($env = @include dirname(__DIR__) . '/.env.local.php') && (! isset($env['APP_ENV']) || ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? $env['APP_ENV']) === $env['APP_ENV'])) {
    (new Dotenv())->usePutenv(false)->populate($env);
} else {
    // load all the .env files
    (new Dotenv())->usePutenv(false)->loadEnv(dirname(__DIR__) . '/.env');
}

$env = is_string($_SERVER['APP_ENV'] ?? null) ? $_SERVER['APP_ENV'] : 'prod';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? ('prod' !== $env));

if ($debug) {
    umask(0o000);
    Debug::enable();
}

$kernel = new Kernel($env, $debug);
$request = Request::createFromGlobals();

// feat #28: trust a defined list of proxy
if (! empty($_SERVER['TRUSTED_PROXY_LIST']) && is_string($_SERVER['TRUSTED_PROXY_LIST'])) {
    $proxyList = json_decode($_SERVER['TRUSTED_PROXY_LIST'], true);
    if (is_array($proxyList)) {
        $trustedHeaderSet = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;
        Request::setTrustedProxies(
            $proxyList,
            $trustedHeaderSet,
        );
    }
}

// feat #28: trust all remote addresses
if (! empty($_SERVER['TRUSTED_PROXY_ALL'])) {
    $trustedHeaderSet = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT;
    Request::setTrustedProxies(
        ['127.0.0.1', $request->server->get('REMOTE_ADDR')],
        $trustedHeaderSet,
    );
}

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
