<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Controller\BaseController;
use App\Dto\InterpretationFiltersDto;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Response\Error;
use App\Service\Entry\EntryQueryService;
use App\Service\Response\PaginationLinkService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;

final class GetAllEntriesAction extends BaseController
{
    public function __construct(
        private readonly EntryQueryService $entryQueryService,
        private readonly PaginationLinkService $paginationLinkService,
    ) {
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/allEntries', name: 'interpretation_all_entries_attr', methods: ['POST'])]
    public function __invoke(Request $request, #[MapQueryString] InterpretationFiltersDto $interpretationFiltersDto): ModelResponse|JsonResponse|Error
    {
        if (false === $this->isPl($request)) {
            return $this->getFailedAuthorizationResponse();
        }

        try {
            $paginatedEntries = $this->entryQueryService->findPaginatedEntries($interpretationFiltersDto);
        } catch (Exception $exception) {
            // Return appropriate status codes based on error type
            if (str_contains($exception->getMessage(), 'Failed to parse')) {
                $statusCode = \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY; // 422
            } elseif (str_contains($exception->getMessage(), 'page can not be negative')) {
                $statusCode = \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST; // 400
            } else {
                $statusCode = \Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE; // 406
            }

            return new Error($this->translate($exception->getMessage()), $statusCode);
        }

        $links = $this->paginationLinkService->generateLinks($request, $paginatedEntries);
        $responseData = array_merge($links, ['data' => $paginatedEntries->toArray()]);

        return new JsonResponse($responseData);
    }
}
