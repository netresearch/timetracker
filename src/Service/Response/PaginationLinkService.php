<?php

declare(strict_types=1);

namespace App\Service\Response;

use App\ValueObject\PaginatedEntryCollection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Service for generating pagination links in API responses.
 */
final readonly class PaginationLinkService
{
    /**
     * Generate pagination links for API response.
     *
     * @return array<string, mixed>
     */
    public function generateLinks(Request $request, PaginatedEntryCollection $collection): array
    {
        $route = $request->getUriForPath($request->getPathInfo()) . '?';
        $queryParams = [];
        
        if ($request->getQueryString()) {
            parse_str($request->getQueryString(), $queryParams);
            unset($queryParams['page']); // Remove existing page parameter
            // Ensure query params are string-keyed mixed values for type safety
            /** @var array<string, mixed> $queryParams */
            $queryParams = array_filter($queryParams, 'is_scalar');
        }

        if ($collection->totalCount === 0) {
            return $this->getEmptyLinks($route, $queryParams, $collection->currentPage);
        }

        $links = [];

        // Self link (current page)
        $queryParams['page'] = $collection->currentPage;
        $links['self'] = $route . http_build_query($queryParams);

        // Last page link
        $queryParams['page'] = $collection->getLastPage();
        $links['last'] = $route . http_build_query($queryParams);

        // Previous page link
        $links['prev'] = $collection->hasPreviousPage() 
            ? $route . http_build_query(array_merge($queryParams, ['page' => $collection->getPreviousPage()]))
            : null;

        // Next page link
        $links['next'] = $collection->hasNextPage()
            ? $route . http_build_query(array_merge($queryParams, ['page' => $collection->getNextPage()]))
            : null;

        return ['links' => $links];
    }

    /**
     * Get links structure when there are no results.
     *
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    private function getEmptyLinks(string $route, array $queryParams, int $currentPage): array
    {
        $queryParams['page'] = $currentPage;
        $self = $route . http_build_query($queryParams);
        
        return [
            'links' => [
                'self' => $self,
                'last' => null,
                'prev' => null,
                'next' => null,
            ],
        ];
    }
}