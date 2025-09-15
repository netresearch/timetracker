<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Entry;
use App\Model\JsonResponse;
use App\Response\Error;
use App\Service\Util\TimeCalculationService;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_array;

final class GetSummaryAction extends BaseController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    /**
     * @throws InvalidArgumentException                                        When request parameters are invalid
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When request parameters are malformed
     * @throws Exception                                                       When database operations fail
     * @throws Exception                                                       When time calculation operations fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/getSummary', name: '_getSummary_attr', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[\Symfony\Component\Security\Http\Attribute\CurrentUser] ?\App\Entity\User $user = null): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response|JsonResponse|Error
    {
        if (!$user instanceof \App\Entity\User) {
            return $this->redirectToRoute('_login');
        }

        $userId = (int) $user->getId();

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

        // Priority 1: Fix PossiblyUndefinedArrayOffset with proper array access validation
        if (isset($data['project']) && is_array($data['project']) && isset($data['project']['estimation']) && $data['project']['estimation']) {
            // Safely access nested array values with null coalescing and type validation
            $projectTotal = null;
            $projectEstimation = null;

            if (isset($data['project']['total'])) {
                $projectTotal = is_numeric($data['project']['total']) ? (float) $data['project']['total'] : 0.0;
            }

            if (isset($data['project']['estimation'])) {
                $projectEstimation = is_numeric($data['project']['estimation']) ? (float) $data['project']['estimation'] : 0.0;
            }

            // Only calculate quota if both values are available and valid
            if (null !== $projectTotal && null !== $projectEstimation) {
                $data['project']['quota'] = $this->timeCalculationService->formatQuota(
                    $projectTotal,
                    $projectEstimation,
                );
            }
        }

        return new JsonResponse($data);
    }
}
