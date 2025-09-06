<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Response\Error;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GetSummaryAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/getSummary', name: '_getSummary_attr', methods: ['POST'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse|Error
    {
        if (!$this->checkLogin($request)) {
            return $this->login($request);
        }

        $userId = $this->getUserId($request);

        $data = [
            'customer' => ['scope' => 'customer', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'project' => ['scope' => 'project', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'activity' => ['scope' => 'activity', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
            'ticket' => ['scope' => 'ticket', 'name' => '', 'entries' => 0, 'total' => 0, 'own' => 0, 'estimation' => 0],
        ];

        $entryId = $request->request->get('id');
        if (null === $entryId || '' === $entryId || false === $entryId) {
            return new JsonResponse($data);
        }

        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        if (!$objectRepository->find($entryId)) {
            $message = $this->translator->trans('No entry for id.');

            return new Error($message, \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        $data = $objectRepository->getEntrySummary((int) $entryId, $userId, $data);

        if ($data['project']['estimation']) {
            $total = is_numeric($data['project']['total']) ? (float) $data['project']['total'] : 0.0;
            $estimation = is_numeric($data['project']['estimation']) ? (float) $data['project']['estimation'] : 0.0;
            $data['project']['quota'] = $this->timeCalculationService->formatQuota(
                $total,
                $estimation,
            );
        }

        return new JsonResponse($data);
    }
}
