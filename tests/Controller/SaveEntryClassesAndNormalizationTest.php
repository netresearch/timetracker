<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\Entry;
use App\Enum\EntryClass;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

/**
 * Day-class recalculation (day break / pause / overlap, see #305) and
 * v4-parity ticket normalization on /tracking/save.
 *
 * @internal
 *
 * @coversNothing
 */
final class SaveEntryClassesAndNormalizationTest extends AbstractWebTestCase
{
    private const string NINE = '09:00:00';
    private const string TEN = '10:00:00';

    /**
     * @param array<string, string|int> $overrides
     *
     * @return array<string, string|int>
     */
    private function saveParameters(array $overrides = []): array
    {
        return $overrides + [
            'date' => '2024-03-11',
            'project_id' => 1,
            'customer_id' => 1,
            'activity_id' => 1,
        ];
    }

    /**
     * @param array<string, string|int> $parameters
     *
     * @return array<mixed>
     */
    private function saveEntry(array $parameters): array
    {
        $this->client->request(Request::METHOD_POST, '/tracking/save', $parameters, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('result', $data);
        self::assertIsArray($data['result']);

        return $data['result'];
    }

    public function testFirstEntryOfDayGetsDaybreakClass(): void
    {
        $this->logInSession('unittest');

        $result = $this->saveEntry($this->saveParameters(['start' => self::NINE, 'end' => self::TEN]));

        self::assertSame(EntryClass::DAYBREAK->value, $result['class']);
    }

    public function testSeamlessFollowUpEntryGetsPlainClass(): void
    {
        $this->logInSession('unittest');

        $this->saveEntry($this->saveParameters(['start' => self::NINE, 'end' => self::TEN]));
        $result = $this->saveEntry($this->saveParameters(['start' => self::TEN, 'end' => '10:30:00']));

        self::assertSame(EntryClass::PLAIN->value, $result['class']);
    }

    public function testEntryAfterGapGetsPauseClass(): void
    {
        $this->logInSession('unittest');

        $this->saveEntry($this->saveParameters(['start' => self::NINE, 'end' => self::TEN]));
        $result = $this->saveEntry($this->saveParameters(['start' => '10:30:00', 'end' => '11:00:00']));

        self::assertSame(EntryClass::PAUSE->value, $result['class']);
    }

    public function testOverlappingEntryGetsOverlapClass(): void
    {
        $this->logInSession('unittest');

        $this->saveEntry($this->saveParameters(['start' => self::NINE, 'end' => self::TEN]));
        $result = $this->saveEntry($this->saveParameters(['start' => '09:30:00', 'end' => '10:30:00']));

        self::assertSame(EntryClass::OVERLAP->value, $result['class']);
    }

    public function testTicketIsUpperCasedAndTrimmedOnSave(): void
    {
        $this->logInSession('unittest');

        // fixture project 1 has jira_id 'SA'; lower-case input must pass the
        // prefix validation and be stored normalized (v4 parity)
        $result = $this->saveEntry($this->saveParameters([
            'start' => self::NINE,
            'end' => self::TEN,
            'ticket' => 'sa-123',
        ]));

        self::assertSame('SA-123', $result['ticket']);
    }

    public function testExtTicketIsPersistedAsInternalJiraTicketOriginalKey(): void
    {
        $this->logInSession('unittest');

        $result = $this->saveEntry($this->saveParameters([
            'start' => self::NINE,
            'end' => self::TEN,
            'ticket' => 'SA-77',
            'extTicket' => 'EXT-77',
        ]));

        $entryId = $result['id'];
        self::assertIsInt($entryId);

        $container = $this->client->getContainer();
        /** @var \Doctrine\Bundle\DoctrineBundle\Registry $doctrine */
        $doctrine = $container->get('doctrine');
        $entry = $doctrine->getManager()->getRepository(Entry::class)->find($entryId);

        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('EXT-77', $entry->getInternalJiraTicketOriginalKey());
    }
}
