<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;

final class GetLastEntriesAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request parameters are invalid
     * @throws Exception When database operations fail
     * @throws \Exception When entry retrieval or time calculation fails
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/entries', name: 'interpretation_entries_attr', methods: ['GET'])]
    public function __invoke(Request $request): ModelResponse|JsonResponse
    {
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getEntries($request, 50);
        } catch (Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $sum = $this->calculateSum($entries);
        $entryList = [];
        foreach ($entries as $entry) {
            $flatEntry = $entry->toArray();
            $flatEntry['quota'] = $this->timeCalculationService->formatQuota($flatEntry['duration'], $sum);
            $flatEntry['duration'] = $this->timeCalculationService->formatDuration($flatEntry['duration']);
            $entryList[] = ['entry' => $flatEntry];
        }

        return new JsonResponse($entryList);
    }
}
