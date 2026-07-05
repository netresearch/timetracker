<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;
use Tests\Traits\HttpRequestTestTrait;

use function sprintf;

use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

/**
 * @internal
 *
 * @coversNothing
 */
final class GetStatusActionTest extends AbstractWebTestCase
{
    use HttpRequestTestTrait;

    public function testReturnsDiagnosticsForAdmin(): void
    {
        // AbstractWebTestCase logs in as 'unittest', whose type is ADMIN.
        $this->getJson('/admin/status');
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());
        foreach (['app', 'build', 'php', 'symfony', 'packages', 'database', 'subsystems', 'config'] as $section) {
            self::assertArrayHasKey($section, $json);
        }
        self::assertIsArray($json['php']);
        self::assertIsArray($json['database']);
        self::assertSame(PHP_VERSION, $json['php']['version']);
        self::assertArrayHasKey('platform', $json['database']);

        // Build provenance: the GitHub links are always present; the commit/ref
        // are null in the test env (no APP_BUILD_* baked in) → no fabricated link.
        self::assertIsArray($json['build']);
        self::assertSame('https://github.com/netresearch/timetracker', $json['build']['repositoryUrl']);
        self::assertSame('https://github.com/netresearch/timetracker/releases', $json['build']['releasesUrl']);
        self::assertNull($json['build']['revision']);
        self::assertNull($json['build']['commitUrl']);

        // Never leaks credentials: no password anywhere, no DB user key.
        self::assertStringNotContainsStringIgnoringCase('password', (string) json_encode($json));
        self::assertArrayNotHasKey('user', $json['database']);
    }

    public function testSubsystemCardsCoverExpectedStorage(): void
    {
        $this->getJson('/admin/status');
        $this->assertStatusCode(200);

        $json = $this->getJsonResponse($this->client->getResponse());
        self::assertIsArray($json['subsystems']);

        $byId = [];
        foreach ($json['subsystems'] as $card) {
            self::assertIsArray($card);
            foreach (['id', 'backend', 'status', 'config', 'adr'] as $key) {
                self::assertArrayHasKey($key, $card);
            }
            $id = $card['id'];
            self::assertIsString($id);
            $byId[$id] = $card;
        }

        // Every storage/subsystem the page promises is present.
        foreach (['database', 'sessions', 'cache', 'api_tokens', 'passkeys_mfa', 'authentication', 'api', 'jira'] as $id) {
            self::assertArrayHasKey($id, $byId, sprintf('missing subsystem card: %s', $id));
        }

        // The token card reports live counts and the recognizable prefix.
        $apiTokenConfig = $byId['api_tokens']['config'];
        self::assertIsArray($apiTokenConfig);
        self::assertIsInt($apiTokenConfig['active']);
        self::assertSame('tt_pat_', $apiTokenConfig['prefix']);

        // Sessions are file-based and link ADR-019 — a regression guard against
        // silently reporting a shared backend that is not actually deployed.
        self::assertSame('ADR-019', $byId['sessions']['adr']);
        $sessionsBackend = $byId['sessions']['backend'];
        self::assertIsString($sessionsBackend);
        self::assertStringContainsStringIgnoringCase('file', $sessionsBackend);

        // The richer payload still leaks no credentials.
        self::assertStringNotContainsStringIgnoringCase('password', json_encode($json, JSON_THROW_ON_ERROR));
    }

    public function testForbiddenForNonAdmin(): void
    {
        $this->logInSession('developer'); // type DEV — not an admin
        $this->getJson('/admin/status');
        $this->assertStatusCode(403);
    }
}
