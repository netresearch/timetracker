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
}
