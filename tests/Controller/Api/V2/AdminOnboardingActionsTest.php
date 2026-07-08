<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Api\V2;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\MintsApiTokens;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The v2 admin endpoints (ADR-022 Phase 3): onboarding and active-toggling of
 * projects, customers and users, with the double gate — admin role AND, for
 * tokens, the matching write scope. Session user is 'unittest' (ADMIN);
 * 'developer' (DEV) is the non-admin.
 *
 * @internal
 */
final class AdminOnboardingActionsTest extends AbstractWebTestCase
{
    use MintsApiTokens;

    public function testOnboardProjectAsAdminSession(): void
    {
        $this->postJson('/api/v2/projects', ['name' => 'Launchpad', 'customer_id' => 1, 'jira_id' => 'LPD']);
        $this->assertStatusCode(201);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['project']);
        self::assertSame('Launchpad', $data['project']['name']);
        self::assertSame('LPD', $data['project']['jira_id']);
        self::assertTrue($data['project']['active']);
    }

    public function testOnboardProjectValidationFailureAnswers422(): void
    {
        $this->postJson('/api/v2/projects', ['name' => 'X', 'customer_id' => 1]);

        $this->assertStatusCode(422);
    }

    public function testOnboardCustomerNeedsTeamUnlessGlobal(): void
    {
        $this->postJson('/api/v2/customers', ['name' => 'Teamless GmbH']);
        $this->assertStatusCode(422);

        $this->postJson('/api/v2/customers', ['name' => 'Teamful GmbH', 'team_ids' => [1]]);
        $this->assertStatusCode(201);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['customer']);
        self::assertSame([1], $data['customer']['team_ids']);
    }

    public function testOnboardUserAndOffboardAgain(): void
    {
        $this->postJson('/api/v2/users', ['username' => 'fresh.face', 'abbr' => 'FFC', 'team_ids' => [1]]);
        $this->assertStatusCode(201);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['user']);
        $id = $data['user']['id'];
        self::assertIsInt($id);
        self::assertTrue($data['user']['active']);

        $this->client->request(Request::METHOD_POST, "/api/v2/users/{$id}/deactivate");
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['user']);
        self::assertFalse($data['user']['active']);
    }

    public function testToggleUnknownProjectIsNotFound(): void
    {
        $this->client->request(Request::METHOD_POST, '/api/v2/projects/999999/deactivate');

        $this->assertStatusCode(404);
    }

    public function testNonAdminSessionIsForbidden(): void
    {
        $this->logInSession('developer');

        $this->postJson('/api/v2/projects', ['name' => 'Nope Project', 'customer_id' => 1]);

        $this->assertStatusCode(403);
    }

    public function testAdminTokenWithScopeCreates(): void
    {
        $status = $this->postJsonWithToken('/api/v2/projects', $this->mintToken(['projects:write']), ['name' => 'Token Project', 'customer_id' => 1]);

        self::assertSame(201, $status);
    }

    public function testAdminTokenWithoutWriteScopeIsForbidden(): void
    {
        $status = $this->postJsonWithToken('/api/v2/projects', $this->mintToken(['projects:read']), ['name' => 'Read Only', 'customer_id' => 1]);

        self::assertSame(403, $status);
    }

    public function testNonAdminTokenWithScopeIsForbidden(): void
    {
        // The scope narrows the user's rights; it never expands them (ADR-021).
        $status = $this->postJsonWithToken('/api/v2/projects', $this->mintToken(['projects:write'], 'developer'), ['name' => 'Sneaky Project', 'customer_id' => 1]);

        self::assertSame(403, $status);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function postJson(string $path, array $json): void
    {
        $this->client->request(
            Request::METHOD_POST,
            $path,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            json_encode($json, JSON_THROW_ON_ERROR),
        );
    }
}
