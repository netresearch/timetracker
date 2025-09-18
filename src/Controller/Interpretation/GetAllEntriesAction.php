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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetAllEntriesAction extends BaseController
{
    public function __construct(
        private readonly EntryQueryService $entryQueryService,
        private readonly PaginationLinkService $paginationLinkService,
    ) {
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/allEntries', name: 'interpretation_all_entries_attr', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function __invoke(Request $request, #[MapQueryString] InterpretationFiltersDto $interpretationFiltersDto, #[CurrentUser] ?\App\Entity\User $user = null): ModelResponse|JsonResponse|Error
    {
        // Check if user is either admin or PL type
        if (!$this->isGranted('ROLE_ADMIN')) {
            if ($user === null || !$user->getType()->isPl()) {
                return new Error($this->translate('You are not allowed to perform this action.'), \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN);
            }
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