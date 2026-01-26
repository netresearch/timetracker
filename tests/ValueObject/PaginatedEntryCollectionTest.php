<?php

declare(strict_types=1);

namespace Tests\ValueObject;

use App\Entity\Entry;
use App\ValueObject\PaginatedEntryCollection;
use DateTime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PaginatedEntryCollection.
 *
 * @internal
 */
#[CoversClass(PaginatedEntryCollection::class)]
final class PaginatedEntryCollectionTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorSetsProperties(): void
    {
        $entries = [new Entry(), new Entry()];
        $collection = new PaginatedEntryCollection(
            entries: $entries,
            totalCount: 100,
            currentPage: 2,
            maxResults: 10,
        );

        self::assertSame($entries, $collection->entries);
        self::assertSame(100, $collection->totalCount);
        self::assertSame(2, $collection->currentPage);
        self::assertSame(10, $collection->maxResults);
    }

    // ==================== toArray tests ====================

    public function testToArrayTransformsEntriesCorrectly(): void
    {
        $entry = new Entry();
        $entry->setDay(new DateTime('2025-01-15'));
        $entry->setStart(new DateTime('09:00:00'));
        $entry->setEnd(new DateTime('17:00:00'));
        $entry->setDescription('Test entry');

        $collection = new PaginatedEntryCollection(
            entries: [$entry],
            totalCount: 1,
            currentPage: 0,
            maxResults: 10,
        );

        $result = $collection->toArray();

        self::assertCount(1, $result);
        self::assertArrayHasKey('date', $result[0]);
        self::assertSame('2025-01-15', $result[0]['date']);
        self::assertArrayHasKey('user_id', $result[0]);
        self::assertArrayHasKey('project_id', $result[0]);
        self::assertArrayHasKey('customer_id', $result[0]);
        self::assertArrayHasKey('activity_id', $result[0]);
        self::assertArrayHasKey('worklog_id', $result[0]);
        // Original keys should be removed
        self::assertArrayNotHasKey('class', $result[0]);
    }

    public function testToArrayReturnsEmptyForEmptyCollection(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 0,
            currentPage: 0,
            maxResults: 10,
        );

        $result = $collection->toArray();

        self::assertSame([], $result);
    }

    // ==================== getLastPage tests ====================

    public function testGetLastPageReturnsZeroForEmptyCollection(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 0,
            currentPage: 0,
            maxResults: 10,
        );

        self::assertSame(0, $collection->getLastPage());
    }

    public function testGetLastPageCalculatesCorrectly(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 0,
            maxResults: 10,
        );

        // 100 items, 10 per page = 10 pages (0-9), so last page is 9
        self::assertSame(9, $collection->getLastPage());
    }

    public function testGetLastPageRoundsUp(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 25,
            currentPage: 0,
            maxResults: 10,
        );

        // 25 items, 10 per page = 3 pages (0-2), so last page is 2
        self::assertSame(2, $collection->getLastPage());
    }

    public function testGetLastPageForExactMultiple(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 30,
            currentPage: 0,
            maxResults: 10,
        );

        // 30 items, 10 per page = 3 pages (0-2), so last page is 2
        self::assertSame(2, $collection->getLastPage());
    }

    // ==================== hasPreviousPage tests ====================

    public function testHasPreviousPageReturnsFalseOnFirstPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 0,
            maxResults: 10,
        );

        self::assertFalse($collection->hasPreviousPage());
    }

    public function testHasPreviousPageReturnsTrueOnSecondPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 1,
            maxResults: 10,
        );

        self::assertTrue($collection->hasPreviousPage());
    }

    // ==================== hasNextPage tests ====================

    public function testHasNextPageReturnsFalseOnLastPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 9,  // Last page for 100 items, 10 per page
            maxResults: 10,
        );

        self::assertFalse($collection->hasNextPage());
    }

    public function testHasNextPageReturnsTrueOnFirstPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 0,
            maxResults: 10,
        );

        self::assertTrue($collection->hasNextPage());
    }

    public function testHasNextPageReturnsFalseForSinglePage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 5,
            currentPage: 0,
            maxResults: 10,
        );

        self::assertFalse($collection->hasNextPage());
    }

    // ==================== getPreviousPage tests ====================

    public function testGetPreviousPageReturnsNullOnFirstPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 0,
            maxResults: 10,
        );

        self::assertNull($collection->getPreviousPage());
    }

    public function testGetPreviousPageReturnsCorrectPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 5,
            maxResults: 10,
        );

        self::assertSame(4, $collection->getPreviousPage());
    }

    // ==================== getNextPage tests ====================

    public function testGetNextPageReturnsNullOnLastPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 9,  // Last page
            maxResults: 10,
        );

        self::assertNull($collection->getNextPage());
    }

    public function testGetNextPageReturnsCorrectPage(): void
    {
        $collection = new PaginatedEntryCollection(
            entries: [],
            totalCount: 100,
            currentPage: 5,
            maxResults: 10,
        );

        self::assertSame(6, $collection->getNextPage());
    }
}
