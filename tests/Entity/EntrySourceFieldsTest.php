<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Entry;
use App\Enum\EntrySource;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

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

    public function testRemovalLeavesAPartnerThatPointsElsewhereAlone(): void
    {
        // No application path writes a one-sided link, but a hand-edited row can: removing
        // $stale must not break the $partner <-> $other pair it still points into.
        $partner = new Entry();
        $other = new Entry();
        $partner->pairWith($other);
        $stale = new Entry();
        new ReflectionProperty(Entry::class, 'pairedEntry')->setValue($stale, $partner);

        $stale->unlinkPartnerOnRemove();

        self::assertSame($other, $partner->getPairedEntry());
    }

    public function testPairingTheSamePairAgainChangesNothing(): void
    {
        $agent = new Entry();
        $human = new Entry();
        $agent->pairWith($human);

        $human->pairWith($agent);

        self::assertSame($human, $agent->getPairedEntry());
        self::assertSame($agent, $human->getPairedEntry());
    }

    public function testRefusesToPairAnEntryThatIsPairedElsewhere(): void
    {
        // Moving a link cannot be written in one flush without risking the unique index,
        // so an existing pair is never silently rewired — from either side.
        $paired = new Entry();
        $paired->pairWith(new Entry());

        $this->expectException(LogicException::class);

        $paired->pairWith(new Entry());
    }

    public function testRefusesToPairWithAnEntryThatIsPairedElsewhere(): void
    {
        $paired = new Entry();
        $paired->pairWith(new Entry());

        $this->expectException(LogicException::class);

        new Entry()->pairWith($paired);
    }

    public function testRefusesToPairAnEntryWithItself(): void
    {
        $entry = new Entry();

        $this->expectException(LogicException::class);

        $entry->pairWith($entry);
    }
}
