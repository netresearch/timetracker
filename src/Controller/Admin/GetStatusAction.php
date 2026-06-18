<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use ReflectionClass;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function extension_loaded;
use function is_scalar;
use function is_string;

use const PHP_VERSION;

/**
 * Read-only diagnostics for admins and bug reports: app/PHP/Symfony/package
 * versions, the database driver + server version + connection (NO credentials),
 * and the relevant non-secret configuration.
 */
final class GetStatusAction extends BaseController
{
    /** Composer packages worth surfacing (guarded — unknown ones are skipped). */
    private const PACKAGES = [
        'symfony/framework-bundle',
        'doctrine/orm',
        'doctrine/dbal',
        'doctrine/doctrine-bundle',
        'twig/twig',
        'nelmio/api-doc-bundle',
        'laminas/laminas-ldap',
    ];

    /** PHP extensions worth surfacing if loaded (the full list is noise). */
    private const EXTENSIONS = [
        'pdo_mysql', 'mysqli', 'intl', 'mbstring', 'openssl', 'ldap',
        'curl', 'json', 'opcache', 'sodium', 'gd', 'zip',
    ];

    #[Route(path: '/admin/status', name: 'admin_status_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Connection $connection): JsonResponse
    {
        return new JsonResponse([
            'app' => [
                'title' => $this->param('app_title'),
                'env' => $this->kernel->getEnvironment(),
                'debug' => $this->kernel->isDebug(),
                'locale' => $this->param('app_locale'),
                'version' => InstalledVersions::isInstalled('netresearch/timetracker')
                    ? InstalledVersions::getPrettyVersion('netresearch/timetracker')
                    : null,
            ],
            'php' => [
                'version' => PHP_VERSION,
                'extensions' => array_values(array_filter(
                    self::EXTENSIONS,
                    static fn (string $ext): bool => extension_loaded($ext),
                )),
            ],
            'symfony' => [
                'kernel' => Kernel::VERSION,
            ],
            'packages' => $this->packageVersions(),
            'database' => $this->databaseInfo($connection),
            'config' => [
                'ldap_host' => $this->param('ldap_host'),
                'ldap_port' => $this->param('ldap_port'),
                'ldap_basedn' => $this->param('ldap_basedn'),
                'ldap_ssl' => $this->param('ldap_usessl'),
                'ldap_create_user' => $this->param('ldap_create_user'),
            ],
        ]);
    }

    /** A container parameter, or null when it isn't defined. */
    private function param(string $key): mixed
    {
        return $this->params->has($key) ? $this->params->get($key) : null;
    }

    /** @return array<string, string|null> */
    private function packageVersions(): array
    {
        $versions = [];
        foreach (self::PACKAGES as $package) {
            if (!InstalledVersions::isInstalled($package)) {
                continue;
            }

            try {
                $versions[$package] = InstalledVersions::getPrettyVersion($package);
            } catch (Throwable) {
                $versions[$package] = null;
            }
        }

        return $versions;
    }

    /**
     * Driver, platform, server version and connection host/name. The platform +
     * version + schema come from the live connection (avoiding DBAL's @internal
     * getParams()/getServerVersion()); the host/port/driver are parsed from the
     * DSN — scheme/host/port/path only, so the user:password can't leak.
     *
     * @return array<string, string|null>
     */
    private function databaseInfo(Connection $connection): array
    {
        $str = static fn (mixed $value): ?string => is_scalar($value) ? (string) $value : null;

        $platform = null;
        $serverVersion = null;
        $schema = null;
        try {
            $platform = new ReflectionClass($connection->getDatabasePlatform())->getShortName();
            // VERSION()/DATABASE() (MySQL/MariaDB) — public DBAL API, no @internal call.
            $serverVersion = $str($connection->fetchOne('SELECT VERSION()'));
            $schema = $str($connection->fetchOne('SELECT DATABASE()'));
        } catch (Throwable) {
            // A diagnostics page must never 500 just because the DB is unreachable.
        }

        $dsn = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
        $parts = [];
        if (is_string($dsn)) {
            $parsed = parse_url($dsn);
            if (false !== $parsed) {
                $parts = $parsed;
            }
        }

        return [
            'driver' => $str($parts['scheme'] ?? null),
            'platform' => $platform,
            'serverVersion' => $serverVersion,
            'host' => $str($parts['host'] ?? null),
            'port' => $str($parts['port'] ?? null),
            'name' => $schema ?? $str(isset($parts['path']) ? ltrim((string) $parts['path'], '/') : null),
        ];
    }
}
