<?php

declare(strict_types=1);

namespace App\Service\Entry;

use App\Dto\InterpretationFiltersDto;
use App\Entity\Entry;
use App\Repository\EntryRepository;
use App\ValueObject\PaginatedEntryCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Exception;

use function is_string;

/**
 * Service for querying and paginating entries with proper type safety.
 */
final readonly class EntryQueryService
{
    public function __construct(
        private EntryRepository $entryRepository,
    ) {
    }

    /**
     * Find paginated entries based on filters.
     *
     * @throws Exception When query building fails
     */
    public function findPaginatedEntries(InterpretationFiltersDto $filters): PaginatedEntryCollection
    {
        $searchArray = $this->buildSearchArray($filters);

        $query = $this->entryRepository->queryByFilterArray($searchArray);
        // queryByFilterArray always returns Query, no need for instanceof check

        $paginator = new Paginator($query);

        /** @var Entry[] $entries */
        $entries = $paginator->getQuery()->getResult();

        // No need to filter Entry instances - getResult() always returns Entry[]

        /** @var int $maxResults */
        $maxResults = $searchArray['maxResults'];

        return new PaginatedEntryCollection(
            entries: $entries,
            totalCount: $paginator->count(),
            currentPage: $filters->page ?? 0,
            maxResults: $maxResults,
        );
    }

    /**
     * Build search array from filters with validation.
     *
     * @return array<string, mixed>
     */
    private function buildSearchArray(InterpretationFiltersDto $filters): array
    {
        // Handle legacy *_id aliases through the DTO
        $project = $filters->project ?? $filters->project_id ?? 0;
        $customer = $filters->customer ?? $filters->customer_id ?? 0;
        $activity = $filters->activity ?? $filters->activity_id ?? 0;
        $maxResults = $filters->maxResults ?? 0;
        $page = $filters->page ?? 0;

        // Validate and sanitize inputs
        if ($page < 0) {
            throw new Exception('page can not be negative.');
        }

        $maxResults = $maxResults > 0 ? $maxResults : 50;

        $searchArray = [
            'maxResults' => $maxResults,
            'page' => $page,
        ];

        // Add non-zero filters
        if (0 !== $activity) {
            $searchArray['activity'] = $activity;
        }

        if (0 !== $project) {
            $searchArray['project'] = $project;
        }

        if (0 !== $customer) {
            $searchArray['customer'] = $customer;
        }

        // Add string filters if they're valid
        if (is_string($filters->datestart) && '' !== $filters->datestart) {
            $searchArray['datestart'] = $filters->datestart;
        }

        if (is_string($filters->dateend) && '' !== $filters->dateend) {
            $searchArray['dateend'] = $filters->dateend;
        }

        return $searchArray;
    }
}
