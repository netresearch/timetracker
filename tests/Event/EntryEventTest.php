<?php

declare(strict_types=1);

namespace Tests\Event;

use App\Entity\Entry;
use App\Event\EntryEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EntryEvent.
 *
 * @internal
 */
#[CoversClass(EntryEvent::class)]
final class EntryEventTest extends TestCase
{
    public function testGetEntryReturnsEntry(): void
    {
        $entry = new Entry();
        $event = new EntryEvent($entry);

        self::assertSame($entry, $event->getEntry());
    }

    public function testGetContextReturnsNullByDefault(): void
    {
        $entry = new Entry();
        $event = new EntryEvent($entry);

        self::assertNull($event->getContext());
    }

    public function testGetContextReturnsContextWhenProvided(): void
    {
        $entry = new Entry();
        $context = ['key' => 'value', 'nested' => ['data' => 123]];
        $event = new EntryEvent($entry, $context);

        self::assertSame($context, $event->getContext());
    }

    public function testEventConstantsExist(): void
    {
        // Verify event constants are defined
        $this->expectNotToPerformAssertions();

        // Access constants to verify they exist (will fatal error if not defined)
        $_ = EntryEvent::CREATED;
        $_ = EntryEvent::UPDATED;
        $_ = EntryEvent::DELETED;
        $_ = EntryEvent::SYNCED;
        $_ = EntryEvent::SYNC_FAILED;
    }
}
