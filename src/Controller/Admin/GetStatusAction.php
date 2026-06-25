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
    /** Canonical GitHub project, used to build the provenance links. */
    private const string REPOSITORY_URL = 'https://github.com/netresearch/timetracker';

    /** Composer packages worth surfacing (guarded — unknown ones are skipped). */
    private const array PACKAGES = [
        'symfony/framework-bundle',
        'doctrine/orm',
        'doctrine/dbal',
        'doctrine/doctrine-bundle',
        'twig/twig',
        'nelmio/api-doc-bundle',
        'laminas/laminas-ldap',
    ];

    /** PHP extensions worth surfacing if loaded (the full list is noise). */
    private const array EXTENSIONS = [
        'pdo_mysql', 'mysqli', 'intl', 'mbstring', 'openssl', 'ldap',
        'curl', 'json', 'opcache', 'sodium', 'gd', 'zip',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route(path: '/admin/status', name: 'admin_status_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): JsonResponse
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
            'build' => $this->buildInfo(),
            'php' => [
                'version' => PHP_VERSION,
                'extensions' => array_values(array_filter(
                    self::EXTENSIONS,
                    extension_loaded(...),
                )),
            ],
            'symfony' => [
                'kernel' => Kernel::VERSION,
            ],
            'packages' => $this->packageVersions(),
            'database' => $this->databaseInfo($this->connection),
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

    /**
     * Build provenance (commit, branch/tag, build date) baked into the image by
     * docker bake, plus the matching GitHub links. Everything is null on a plain
     * local build that wasn't given the APP_BUILD_* env, so the page degrades to
     * "unknown" rather than inventing values.
     *
     * @return array<string, string|null>
     */
    private function buildInfo(): array
    {
        $env = static function (string $key): ?string {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;

            return is_string($value) && '' !== $value ? $value : null;
        };

        $revision = $env('APP_BUILD_REVISION');
        $ref = $env('APP_BUILD_REF');

        return [
            'revision' => $revision,
            'ref' => $ref,
            'date' => $env('APP_BUILD_DATE'),
            'repositoryUrl' => self::REPOSITORY_URL,
            'commitUrl' => null !== $revision ? self::REPOSITORY_URL . '/commit/' . $revision : null,
            'refUrl' => null !== $ref ? self::REPOSITORY_URL . '/tree/' . rawurlencode($ref) : null,
            'releasesUrl' => self::REPOSITORY_URL . '/releases',
        ];
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
            'name' => $schema ?? $str(isset($parts['path']) ? ltrim($parts['path'], '/') : null),
        ];
    }
}
