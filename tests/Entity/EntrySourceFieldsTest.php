<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Entry;
use App\Enum\EntrySource;
use PHPUnit\Framework\TestCase;

final class EntrySourceFieldsTest extends TestCase
{
    public function testDefaultsAreHumanNonEstimated(): void
    {
        $entry = new Entry();
        self::assertSame(EntrySource::HUMAN, $entry->getSource());
        self::assertFalse($entry->isEstimated());
        self::assertNull($entry->getResponsibleUser());
        self::assertSame('human', $entry->toArray()['source']);
    }

    public function testAgentAttribution(): void
    {
        $entry = new Entry()->setSource(EntrySource::AGENT)->setEstimated(true)
            ->setTouchpoints(['prompts' => 7, 'reviews' => 2]);
        self::assertSame('agent', $entry->toArray()['source']);
        self::assertTrue($entry->toArray()['estimated']);
        self::assertSame(['prompts' => 7, 'reviews' => 2], $entry->getTouchpoints());
    }

    public function testUnpairedEntryHasNoPartner(): void
    {
        $entry = new Entry();
        self::assertNull($entry->getPairedEntry());
        self::assertNull($entry->toArray()['pairedEntry']);
    }

    public function testPairWithLinksBothSides(): void
    {
        $agent = new Entry()->setSource(EntrySource::AGENT);
        $human = new Entry()->setEstimated(true);

        $agent->pairWith($human);

        self::assertSame($human, $agent->getPairedEntry());
        self::assertSame($agent, $human->getPairedEntry());
    }

    public function testRemovingOneHalfClearsThePartnersBackReference(): void
    {
        $agent = new Entry()->setSource(EntrySource::AGENT);
        $human = new Entry()->setEstimated(true);
        $agent->pairWith($human);

        // Doctrine calls this on EntityManager::remove() — the survivor must not keep
        // pointing at the removed half, or its next flush fails.
        $human->unlinkPartnerOnRemove();

        self::assertNull($agent->getPairedEntry());
    }

    public function testRePairingReleasesThePreviousPartner(): void
    {
        $first = new Entry();
        $second = new Entry();
        $third = new Entry();
        $first->pairWith($second);

        // The unique index allows one link per entry: $first must not keep pointing at
        // $second once $second is paired with $third.
        $third->pairWith($second);

        self::assertNull($first->getPairedEntry());
        self::assertSame($third, $second->getPairedEntry());
        self::assertSame($second, $third->getPairedEntry());

        $first->unlinkPartnerOnRemove();

        self::assertSame($third, $second->getPairedEntry());
    }
}
