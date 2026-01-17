<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\Activity;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

final class GroupByActivityAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[Route(path: '/interpretation/activity', name: 'interpretation_activity_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser]
        User $currentUser,
    ): ModelResponse|JsonResponse {
        try {
            $entries = $this->getEntries($request, $currentUser);
        } catch (Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $activities = [];
        foreach ($entries as $entry) {
            $activityObj = $entry->getActivity();
            if (!$activityObj instanceof Activity) {
                continue;
            }

            $aid = $activityObj->getId();
            if (null === $aid) {
                continue;
            }
            if (!isset($activities[$aid])) {
                $activities[$aid] = ['id' => $aid, 'name' => $activityObj->getName(), 'hours' => 0];
            }

            $activities[$aid]['hours'] += $entry->getDuration() / 60;
        }

        $total = 0.0;
        foreach ($activities as $a) {
            $total += (float) $a['hours'];
        }

        foreach ($activities as &$activity) {
            $activity['quota'] = $this->timeCalculationService->formatQuota($activity['hours'], $total);
        }

        usort($activities, $this->sortByName(...));

        return new JsonResponse($activities);
    }
}
