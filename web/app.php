<?php

use Symfony\Component\HttpFoundation\Request;

/* @var Composer\Autoload\ClassLoader */
$loader = require __DIR__.'/../app/autoload.php';
include_once __DIR__.'/../app/bootstrap.php.cache';

$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
$kernel = new AppCache($kernel);
$request = Request::createFromGlobals();

# feat #28: trust a defined list of proxy
if (!empty($_SERVER['TRUSTED_PROXY_LIST']) && null !== json_decode($_SERVER['TRUSTED_PROXY_LIST'])) {
    Request::setTrustedProxies(
        json_decode($_SERVER['TRUSTED_PROXY_LIST'])
    );
}

# feat #28: trust all remote addresses
if (!empty($_SERVER['TRUSTED_PROXY_ALL']) && true === (bool) $_SERVER['TRUSTED_PROXY_ALL']) {
    Request::setTrustedProxies(
        ['127.0.0.1', $request->server->get('REMOTE_ADDR')]
    );
}

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
