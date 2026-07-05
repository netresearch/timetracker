<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Service\ApiToken\ApiTokenService;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use ReflectionClass;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Throwable;

use function count;
use function extension_loaded;
use function ini_get;
use function is_scalar;
use function is_string;

use const FILTER_VALIDATE_BOOLEAN;
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

    public function __construct(
        private readonly Connection $connection,
        private readonly ApiTokenService $apiTokenService,
    ) {
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
            'subsystems' => $this->subsystems(),
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
            // getenv() reads the real process environment regardless of PHP's
            // variables_order (where the APP_BUILD_* Docker env land); fall back
            // to the superglobals for SAPIs/setups that only populate those.
            $value = getenv($key);
            if (false === $value || '' === $value) {
                $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
            }

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

    /**
     * Storage/subsystem cards for the admin status page: what each subsystem
     * stores, on which backend, its status, and the relevant non-secret config.
     * Every value is read live (ini/env/COUNT) or from config — nothing invented;
     * a subsystem that can't be probed degrades to a null field rather than
     * failing the page. Labels/descriptions are localized on the client by id.
     *
     * @return list<array{id: string, backend: string, status: string, config: array<string, mixed>, adr: string|null}>
     */
    private function subsystems(): array
    {
        $database = $this->databaseInfo($this->connection);
        $databaseBackend = trim(($database['platform'] ?? '') . ' ' . ($database['serverVersion'] ?? ''));
        if ('' === $databaseBackend) {
            $databaseBackend = 'MariaDB';
        }

        $sessionHandler = $this->iniOrNull('session.save_handler') ?? 'files';
        $apcuEnabled = extension_loaded('apcu') && '0' !== ini_get('apc.enabled');
        $taxonomy = $this->apiTokenService->scopeTaxonomy();
        $scopeCount = count($taxonomy['resources']) * count($taxonomy['actions']) + 1;

        // Live COUNT probes up front so the card status reflects whether they
        // actually succeeded — a null (query failed) must not read as a green "ok".
        $activeTokens = $this->countRows('SELECT COUNT(*) FROM api_tokens WHERE revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())');
        $totalTokens = $this->countRows('SELECT COUNT(*) FROM api_tokens');
        $passkeys = $this->countRows('SELECT COUNT(*) FROM webauthn_credentials');
        $totpUsers = $this->countRows('SELECT COUNT(*) FROM users WHERE totp_secret IS NOT NULL');
        $ticketSystems = $this->countRows('SELECT COUNT(*) FROM ticket_systems');
        $bookableTicketSystems = $this->countRows('SELECT COUNT(*) FROM ticket_systems WHERE book_time = 1');

        return [
            [
                'id' => 'database',
                'backend' => $databaseBackend,
                'status' => null !== $database['serverVersion'] ? 'ok' : 'error',
                'config' => [
                    'driver' => $database['driver'],
                    'host' => $database['host'],
                    'port' => $database['port'],
                    'name' => $database['name'],
                ],
                'adr' => null,
            ],
            [
                'id' => 'sessions',
                'backend' => 'files' === $sessionHandler ? 'PHP native file handler (filesystem)' : $sessionHandler,
                'status' => 'ok',
                'config' => [
                    'handler' => $sessionHandler,
                    'save_path' => $this->iniOrNull('session.save_path') ?? '(system default)',
                    'cookie_samesite' => $this->iniOrNull('session.cookie_samesite'),
                    'write_lock' => 'released early on read-only requests',
                ],
                'adr' => 'ADR-019',
            ],
            [
                'id' => 'cache',
                'backend' => 'APCu (in-memory, per process)',
                'status' => $apcuEnabled ? 'ok' : 'degraded',
                'config' => [
                    'adapter' => 'APCu',
                    'enabled' => $apcuEnabled,
                ],
                'adr' => null,
            ],
            [
                'id' => 'api_tokens',
                'backend' => 'Database (SHA-256 hashed)',
                'status' => null === $activeTokens ? 'na' : 'ok',
                'config' => [
                    'active' => $activeTokens,
                    'total' => $totalTokens,
                    'prefix' => ApiTokenService::PREFIX,
                ],
                'adr' => 'ADR-021',
            ],
            [
                'id' => 'passkeys_mfa',
                'backend' => 'Database',
                'status' => null === $passkeys ? 'na' : 'ok',
                'config' => [
                    'passkeys' => $passkeys,
                    'totp_users' => $totpUsers,
                    'webauthn_rp_id' => $this->env('WEBAUTHN_RP_ID'),
                    'require_two_factor' => filter_var($this->env('REQUIRE_TWO_FACTOR') ?? '', FILTER_VALIDATE_BOOLEAN),
                ],
                'adr' => 'ADR-018',
            ],
            [
                'id' => 'authentication',
                'backend' => 'LDAP directory + local accounts',
                'status' => 'ok',
                'config' => [
                    'ldap_host' => $this->stringParam('ldap_host'),
                    'ldap_port' => $this->stringParam('ldap_port'),
                    'ldap_basedn' => $this->stringParam('ldap_basedn'),
                    // Raw param (bool) so the client renders Yes/No, matching the
                    // main config section — stringParam would coerce it to "1"/"0".
                    'ldap_ssl' => $this->param('ldap_usessl'),
                ],
                'adr' => null,
            ],
            [
                'id' => 'api',
                'backend' => 'REST + stateless Bearer token',
                'status' => 'ok',
                'config' => [
                    'auth' => 'Bearer ' . ApiTokenService::PREFIX . '…',
                    'scopes' => $scopeCount,
                    'openapi' => '/api.yml',
                ],
                'adr' => 'ADR-021',
            ],
            [
                'id' => 'jira',
                'backend' => 'Per-user OAuth + ticket systems',
                'status' => null === $ticketSystems ? 'na' : 'ok',
                'config' => [
                    'ticket_systems' => $ticketSystems,
                    'bookable' => $bookableTicketSystems,
                ],
                'adr' => null,
            ],
        ];
    }

    /** A COUNT(*) result as int, or null when the query fails (missing table, DB down). */
    private function countRows(string $sql): ?int
    {
        try {
            $value = $this->connection->fetchOne($sql);

            return is_scalar($value) ? (int) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** A non-empty php.ini value, or null. */
    private function iniOrNull(string $key): ?string
    {
        $value = ini_get($key);

        return is_string($value) && '' !== $value ? $value : null;
    }

    /** A container parameter coerced to a non-empty string, or null. */
    private function stringParam(string $key): ?string
    {
        $value = $this->param($key);

        return is_scalar($value) && '' !== (string) $value ? (string) $value : null;
    }

    /** A non-empty environment value (real env first, then superglobals), or null. */
    private function env(string $key): ?string
    {
        $value = getenv($key);
        if (false === $value || '' === $value) {
            $value = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        }

        return is_string($value) && '' !== $value ? $value : null;
    }
}
