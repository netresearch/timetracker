<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller\Tracking;

use App\Entity\Entry;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function json_decode;
use function str_repeat;

/**
 * Regression tests for https://github.com/netresearch/timetracker/issues/586.
 *
 * entries.description is varchar(255) under strict SQL mode; a longer value
 * used to pass DTO validation (max 1000), explode at flush (1406 "Data too
 * long") and get flattened into a generic 500. It must be rejected with a 422
 * naming the limit. Descriptions containing `!`, `#`, `/` (the reporter's
 * suspects) must save unchanged. Same class of bug for entries.ticket
 * (varchar(32) vs. the previous DTO max of 50).
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveEntryDescriptionLengthTest extends AbstractWebTestCase
{
    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<mixed>
     */
    private function save(array $overrides = []): array
    {
        $this->logInSession('unittest');
        $this->client->request(Request::METHOD_POST, '/tracking/save', $overrides + [
            'date' => '2024-03-12',
            'start' => '09:00:00',
            'end' => '10:00:00',
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);
        $data = json_decode($content, true);
        self::assertIsArray($data);

        return $data;
    }

    public function testSaveAcceptsDescriptionWithSpecialCharacters(): void
    {
        $data = $this->save(['description' => 'ref #439 / MR !1']);

        $this->assertStatusCode(200);
        self::assertIsArray($data['result']);
        self::assertSame('ref #439 / MR !1', $data['result']['description']);
    }

    public function testSaveAcceptsDescriptionAtMaxLength(): void
    {
        $data = $this->save(['description' => str_repeat('y', Entry::DESCRIPTION_MAX_LENGTH)]);

        $this->assertStatusCode(200);
        self::assertIsArray($data['result']);
    }

    public function testSaveRejectsOverlongDescriptionWith422(): void
    {
        $data = $this->save(['description' => str_repeat('x', Entry::DESCRIPTION_MAX_LENGTH + 1)]);

        $this->assertStatusCode(422);
        self::assertArrayHasKey('message', $data);
        self::assertIsString($data['message']);
        self::assertStringContainsString('255', $data['message']);
    }

    public function testSaveRejectsOverlongTicketWith422(): void
    {
        // 33 chars, regex-valid and prefix-valid for project 1 (jira_id 'SA').
        $data = $this->save(['ticket' => 'SA-' . str_repeat('1', 30)]);

        $this->assertStatusCode(422);
        self::assertArrayHasKey('message', $data);
        self::assertIsString($data['message']);
        self::assertStringContainsString('32', $data['message']);
    }
}
