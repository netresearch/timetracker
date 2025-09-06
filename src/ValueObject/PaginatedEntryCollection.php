<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Entity\Entry;

/**
 * Value object representing a paginated collection of entries with type safety.
 */
final readonly class PaginatedEntryCollection
{
    /**
     * @param Entry[] $entries
     */
    public function __construct(
        public array $entries,
        public int $totalCount,
        public int $currentPage,
        public int $maxResults,
    ) {
    }

    /**
     * Transform entries to array format for API responses.
     *
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        $entryList = [];
        foreach ($this->entries as $entry) {
            $flatEntry = $entry->toArray();
            unset($flatEntry['class']);

            // Add computed/formatted fields
            $flatEntry['date'] = $entry->getDay()->format('Y-m-d');
            $flatEntry['user_id'] = $flatEntry['user'];
            $flatEntry['project_id'] = $flatEntry['project'];
            $flatEntry['customer_id'] = $flatEntry['customer'];
            $flatEntry['activity_id'] = $flatEntry['activity'];
            $flatEntry['worklog_id'] = $flatEntry['worklog'];

            // Remove original keys that were renamed
            unset(
                $flatEntry['user'],
                $flatEntry['project'],
                $flatEntry['customer'],
                $flatEntry['activity'],
                $flatEntry['worklog'],
            );

            $entryList[] = $flatEntry;
        }

        return $entryList;
    }

    /**
     * Get the last page number (0-based).
     */
    public function getLastPage(): int
    {
        if (0 === $this->totalCount) {
            return 0;
        }

        return (int) ceil($this->totalCount / $this->maxResults) - 1;
    }

    /**
     * Check if there is a previous page.
     */
    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 0;
    }

    /**
     * Check if there is a next page.
     */
    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getLastPage();
    }

    /**
     * Get the previous page number.
     */
    public function getPreviousPage(): ?int
    {
        return $this->hasPreviousPage() ? min($this->currentPage - 1, $this->getLastPage()) : null;
    }

    /**
     * Get the next page number.
     */
    public function getNextPage(): ?int
    {
        return $this->hasNextPage() ? $this->currentPage + 1 : null;
    }
}
