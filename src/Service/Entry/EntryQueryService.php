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
        if (!$query instanceof Query) {
            $query = $query->getQuery();
        }

        $paginator = new Paginator($query);
        
        /** @var Entry[] $entries */
        $entries = $paginator->getQuery()->getResult();
        
        // Filter to ensure we only have Entry instances (defensive programming)
        $validEntries = array_filter($entries, fn($entry) => $entry instanceof Entry);

        return new PaginatedEntryCollection(
            entries: $validEntries,
            totalCount: $paginator->count(),
            currentPage: $filters->page ?? 0,
            maxResults: $searchArray['maxResults'],
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