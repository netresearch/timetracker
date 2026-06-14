<?php

declare(strict_types=1);

namespace Tests\Event;

use App\Entity\Entry;
use App\Event\EntryEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

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
        // Reflection (not the literals directly) so the assertions are real
        // runtime checks that the event-name constants are defined.
        $constants = new ReflectionClass(EntryEvent::class)->getConstants();

        self::assertArrayHasKey('CREATED', $constants);
        self::assertArrayHasKey('UPDATED', $constants);
        self::assertArrayHasKey('DELETED', $constants);
        self::assertArrayHasKey('SYNCED', $constants);
        self::assertArrayHasKey('SYNC_FAILED', $constants);
    }
}
