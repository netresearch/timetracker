<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class UiSpaActionTest extends AbstractWebTestCase
{
    public function testUiShellRendersForAuthenticatedUser(): void
    {
        $this->client->request(Request::METHOD_GET, '/ui');

        $this->assertStatusCode(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('window.APP_CONFIG', $content);
        self::assertStringContainsString('"userName":"unittest"', $content);
        self::assertStringContainsString('"locale"', $content);
        self::assertStringContainsString('id="app"', $content);
        // Shared chrome from partials/header.html.twig
        self::assertStringContainsString('id="page-header"', $content);
        self::assertStringContainsString('class="main-nav"', $content);
        self::assertStringContainsString('id="user-badge"', $content);
        // Roles are exposed for client-side nav gating; the always-on migrated
        // tabs (settings, help) appear in the shared nav regardless of role.
        self::assertStringContainsString('"roles"', $content);
        self::assertStringContainsString('data-nav="settings"', $content);
        self::assertStringContainsString('data-nav="help"', $content);
        // Drives the Settings page greying-out of the per-user Personio opt-in.
        self::assertStringContainsString('"personioConfigured"', $content);
    }

    public function testCatchAllServesClientSideRoutes(): void
    {
        $this->client->request(Request::METHOD_GET, '/ui/month?year=2026&month=6');

        $this->assertStatusCode(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('window.APP_CONFIG', $content);
    }

    public function testHeaderIsGermanForGermanLocaleUser(): void
    {
        // Seeded user 1 'unittest' has locale 'de' (sql/unittest/002_testdata.sql).
        $this->client->request(Request::METHOD_GET, '/ui');

        $this->assertStatusCode(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="de">', $content);
        self::assertStringContainsString('>Übersicht<', $content);
        self::assertStringContainsString('title="Abmelden"', $content);
    }

    public function testHeaderFollowsUserLocaleOverDefaultLocale(): void
    {
        // The test env default_locale is 'de' (config/packages/test/translation.yaml),
        // so only a user whose saved locale DIFFERS from the default can tell
        // "header follows the user" apart from "header follows the default".
        $this->setUserLocale(1, 'en');

        $this->client->request(Request::METHOD_GET, '/ui');

        $this->assertStatusCode(200);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<html lang="en">', $content);
        self::assertStringContainsString('>Overview<', $content);
        self::assertStringContainsString('title="Logout"', $content);
        self::assertStringNotContainsString('Übersicht', $content);
        self::assertStringNotContainsString('Abmelden', $content);
    }

    private function setUserLocale(int $userId, string $locale): void
    {
        self::assertNotNull($this->serviceContainer);
        $doctrine = $this->serviceContainer->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $user = $doctrine->getRepository(User::class)->find($userId);
        self::assertInstanceOf(User::class, $user);
        $user->setLocale($locale);
        $doctrine->getManager()->flush();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $this->client->getContainer()->get('session')->clear();
        $this->client->getCookieJar()->clear();
        if ($this->client->getContainer()->has('security.token_storage')) {
            $this->client->getContainer()->get('security.token_storage')->setToken(null);
        }

        $this->client->request(Request::METHOD_GET, '/ui/month');

        $this->assertStatusCode(302);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', $location);
    }
}
