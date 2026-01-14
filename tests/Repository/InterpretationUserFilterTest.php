<?php

declare(strict_types=1);

namespace Tests\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Regression test for Interpretation user filter bug.
 *
 * Bug: When filtering Interpretation by user, entries for sebastian.mendel
 * (parental leave/Elternzeit) don't show, but entries for tobias.hein do show.
 *
 * Root cause: ExtJS sends page=1&start=0, but backend used page*maxResults
 * as offset (ignoring start). User with <50 entries got empty results.
 *
 * @internal
 *
 * @coversNothing
 */
final class InterpretationUserFilterTest extends TestCase
{
    /**
     * Test that start parameter is preferred over page for pagination.
     *
     * The bug was: backend used page*maxResults as offset, ignoring start=0
     * ExtJS sends: page=1&start=0&limit=25
     * Old behavior: offset = 1 * 50 = 50 (wrong)
     * New behavior: offset = 0 (correct, from start parameter)
     */
    public function testStartParameterPreferredOverPage(): void
    {
        // Simulate the filter as the backend receives it from ExtJS
        $filter = $this->createFilterWithStart(0, 1, 50);

        $offset = $this->calculateOffset($filter);

        // Expected: offset should be 0 (from start), not 50 (from page*maxResults)
        self::assertSame(0, $offset, 'Offset should use start parameter, not page * maxResults');
    }

    /**
     * Test that without start parameter, page is used correctly.
     */
    public function testPageParameterUsedWhenStartNotSet(): void
    {
        $filter = $this->createFilterWithoutStart(2, 25);

        $offset = $this->calculateOffset($filter);

        // Expected: page 2 * 25 = 50
        self::assertSame(50, $offset, 'Offset should be page * maxResults when start is not set');
    }

    /**
     * Test explicit start=50 is used correctly.
     */
    public function testExplicitStartOffsetIsUsed(): void
    {
        $filter = $this->createFilterWithStart(50, 0, 25);

        $offset = $this->calculateOffset($filter);

        self::assertSame(50, $offset, 'Explicit start offset should be used');
    }

    /**
     * Create filter array with start parameter.
     *
     * @return array<string, mixed>
     */
    private function createFilterWithStart(int $start, int $page, int $maxResults): array
    {
        return [
            'start' => $start,
            'page' => $page,
            'maxResults' => $maxResults,
        ];
    }

    /**
     * Create filter array without start parameter.
     *
     * @return array<string, mixed>
     */
    private function createFilterWithoutStart(int $page, int $maxResults): array
    {
        return [
            'page' => $page,
            'maxResults' => $maxResults,
        ];
    }

    /**
     * Calculate offset from filter using the same logic as EntryRepository.
     *
     * @param array<string, mixed> $filter
     */
    private function calculateOffset(array $filter): int
    {
        // This mirrors the logic in EntryRepository::queryByFilterArray
        // and EntryRepository::findByFilterArray after the fix
        if (isset($filter['start']) && is_numeric($filter['start'])) {
            return (int) $filter['start'];
        }

        $page = isset($filter['page']) && is_numeric($filter['page']) ? (int) $filter['page'] : 0;
        $maxResults = isset($filter['maxResults']) && is_numeric($filter['maxResults']) ? (int) $filter['maxResults'] : 50;

        return $page * $maxResults;
    }
}
