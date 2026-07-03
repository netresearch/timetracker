<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Project;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * Ticket-prefix validation on /tracking/save (v4 parity, see #306).
 *
 * The project's jira_id is a comma-separated list of allowed prefixes
 * (entries trimmed before comparison); the project's internal Jira project
 * key is accepted as an alternative match; prefixes must match exactly,
 * not merely be a string prefix of the ticket.
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveEntryTicketPrefixTest extends AbstractWebTestCase
{
    /**
     * @param array<string, string|int> $overrides
     *
     * @return array<string, string|int>
     */
    private function saveParameters(array $overrides = []): array
    {
        return $overrides + [
            'start' => '09:00:00',
            'end' => '10:00:00',
            'date' => '2024-01-02',
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
        ];
    }

    private function configureProjectOne(?string $jiraId, ?string $internalKey = null, ?string $internalTicketSystem = null): void
    {
        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $em = $doctrine->getManager();
        $project = $em->getRepository(Project::class)->find(1);
        self::assertInstanceOf(Project::class, $project);

        $project->setJiraId($jiraId);
        if (null !== $internalKey) {
            $project->setInternalJiraProjectKey($internalKey);
        }
        if (null !== $internalTicketSystem) {
            $project->setInternalJiraTicketSystem($internalTicketSystem);
        }

        $em->persist($project);
        $em->flush();
    }

    public function testTicketMatchingSingleJiraIdIsAccepted(): void
    {
        $this->logInSession('unittest');

        // Fixture project 1 has jira_id 'SA'
        $this->client->request(Request::METHOD_POST, '/tracking/save', $this->saveParameters(['ticket' => 'SA-123']), [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
    }

    public function testTicketMatchingLaterPrefixInCommaListIsAccepted(): void
    {
        $this->logInSession('unittest');
        $this->configureProjectOne('SA, DHLSUP');

        $this->client->request(Request::METHOD_POST, '/tracking/save', $this->saveParameters(['ticket' => 'DHLSUP-123456']), [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
    }

    public function testTicketMatchingInternalJiraProjectKeyIsAccepted(): void
    {
        $this->logInSession('unittest');
        $this->configureProjectOne('SA', 'OPSDHL', '1');

        $this->client->request(Request::METHOD_POST, '/tracking/save', $this->saveParameters(['ticket' => 'OPSDHL-77']), [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
    }

    public function testTicketWithUnknownPrefixIsRejected(): void
    {
        $this->logInSession('unittest');

        $this->client->request(Request::METHOD_POST, '/tracking/save', $this->saveParameters(['ticket' => 'WRONG-123']), [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        // The 'unittest' user is German, so the message is localized (ADR i18n fix).
        self::assertSame('Das angegebene Ticket hat kein gültiges Präfix.', $data['message']);
    }

    public function testTicketPrefixMustMatchExactlyNotMerelyStartWith(): void
    {
        $this->logInSession('unittest');

        // 'SAX-1' starts with the configured prefix 'SA' as a string, but the
        // Jira project key differs - must be rejected (regression guard for
        // the str_starts_with() implementation).
        $this->client->request(Request::METHOD_POST, '/tracking/save', $this->saveParameters(['ticket' => 'SAX-1']), [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(400);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('message', $data);
        // The 'unittest' user is German, so the message is localized (ADR i18n fix).
        self::assertSame('Das angegebene Ticket hat kein gültiges Präfix.', $data['message']);
    }
}
