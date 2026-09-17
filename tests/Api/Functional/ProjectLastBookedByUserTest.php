<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Api\Functional;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function is_array;

/**
 * `/getAllProjects` ships two last-booking dates per project and they must not be
 * the same thing: `last_activity` is anyone's most recent booking, while
 * `last_booked_by_user` (#687, the tie-break for the entry form's ticket→project
 * derivation) is the CALLER's own. A query that forgot the user filter would
 * still look right in a payload — until it hands one person another person's
 * booking date.
 *
 * @internal
 *
 * @coversNothing
 */
final class ProjectLastBookedByUserTest extends AbstractWebTestCase
{
    private const PROJECT_ID = 1;

    private const OWN_DAY = '2024-03-04';

    private const OTHER_DAY = '2025-07-08';

    public function testLastBookedByUserIgnoresOtherUsersEntries(): void
    {
        self::assertNotNull($this->serviceContainer);
        $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        // The logged-in user booked earlier than somebody else on the same project,
        // so a missing user filter shows up as the OTHER user's later date.
        $this->insertEntry($connection, 1, self::OWN_DAY);
        $this->insertEntry($connection, 2, self::OTHER_DAY);

        try {
            $this->logInSession('unittest');
            $this->client->request(Request::METHOD_GET, '/getAllProjects');
            $this->assertStatusCode(200);

            $project = $this->projectRow($this->getJsonResponse($this->client->getResponse()), self::PROJECT_ID);

            self::assertSame(self::OWN_DAY, $project['last_booked_by_user'] ?? null);
            self::assertSame(self::OTHER_DAY, $project['last_activity'] ?? null);
        } finally {
            $connection->executeStatement(
                'DELETE FROM entries WHERE day IN (:days)',
                ['days' => [self::OWN_DAY, self::OTHER_DAY]],
                ['days' => ArrayParameterType::STRING],
            );
        }
    }

    private function insertEntry(Connection $connection, int $userId, string $day): void
    {
        $connection->insert('entries', [
            'day' => $day,
            'start' => '09:00:00',
            'end' => '10:00:00',
            'customer_id' => 1,
            'project_id' => self::PROJECT_ID,
            'activity_id' => 1,
            'ticket' => 'LASTBOOKED-1',
            'description' => 'last_booked_by_user fixture',
            'duration' => 60,
            'user_id' => $userId,
            'class' => 1,
            'synced_to_ticketsystem' => 0,
            'internal_jira_ticket_original_key' => '',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function projectRow(mixed $payload, int $projectId): array
    {
        self::assertIsArray($payload);
        foreach ($payload as $row) {
            $project = is_array($row) && isset($row['project']) && is_array($row['project']) ? $row['project'] : $row;
            if (is_array($project) && ($project['id'] ?? null) === $projectId) {
                /** @var array<string, mixed> $project */
                return $project;
            }
        }

        self::fail('Project ' . $projectId . ' missing from /getAllProjects');
    }
}
