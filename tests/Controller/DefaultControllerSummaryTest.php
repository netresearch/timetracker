<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;
use Tests\Traits\CreatesTestEntries;

use function json_decode;

/**
 * POST /getSummary — deprecated v1 endpoint (ADR-022): quota formatting and
 * the backported owner scoping (a foreign entry id reads as 404).
 *
 * @internal
 *
 * @coversNothing
 */
final class DefaultControllerSummaryTest extends AbstractWebTestCase
{
    use CreatesTestEntries;

    public function testGetSummaryActionWithProjectEstimationComputesQuota(): void
    {
        $project = $this->requestOwnProjectSummary(estimation: 300);

        self::assertArrayHasKey('quota', $project);
        $quota = $project['quota'];
        // When estimation is set, quota should be a percentage string
        self::assertIsString($quota);
        self::assertStringEndsWith('%', $quota);
        // Deprecated endpoint (ADR-022 §5) — consumers see it at call time.
        self::assertSame('true', $this->client->getResponse()->headers->get('Deprecation'));
    }

    public function testGetSummaryActionWithoutEstimationLeavesZeroQuota(): void
    {
        $project = $this->requestOwnProjectSummary(estimation: 0);

        // Without estimation set, quota remains numeric zero according to default data
        self::assertSame(0, $project['quota'] ?? 0);
    }

    public function testGetSummaryActionForeignEntryReadsAsNotFound(): void
    {
        // Owned by 'developer' (id 2), requested by the session user 'unittest'
        // (id 1): the ADR-022 §5 backport scopes v1 to the caller's own entries.
        $entry = $this->createEntryFor('developer');

        $this->client->request(Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);

        $this->assertStatusCode(404);
    }

    /**
     * Creates an entry owned by the session user, pins its project estimation,
     * POSTs /getSummary for it and returns the decoded `project` scope row.
     *
     * @return array<mixed>
     */
    private function requestOwnProjectSummary(int $estimation): array
    {
        $entry = $this->createEntryFor('unittest', ticket: 'SA-21', description: 'summary quota test entry');
        $project = $entry->getProject();
        self::assertNotNull($project, 'Project should not be null');
        $project->setEstimation($estimation);
        $this->testEntityManager()->flush();

        $this->client->request(Request::METHOD_POST, '/getSummary', ['id' => $entry->getId()]);
        $this->assertStatusCode(200);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('project', $response);
        self::assertIsArray($response['project']);

        return $response['project'];
    }
}
