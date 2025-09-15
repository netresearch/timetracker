<?php

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GroupByUserAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/user', name: 'interpretation_user_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $currentUser,
    ): ModelResponse|JsonResponse {
        $request->query->set('user', $currentUser->getId());

        try {
            $entries = $this->getEntries($request, $currentUser);
        } catch (Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);

            return $response;
        }

        $users = [];
        foreach ($entries as $entry) {
            $u = $entry->getUser();
            if (!$u) {
                continue;
            }

            $uid = $u->getId();
            if (!isset($users[$uid])) {
                $users[$uid] = ['id' => $uid, 'name' => (string) $u->getUsername(), 'hours' => 0, 'quota' => 0];
            }

            $users[$uid]['hours'] += $entry->getDuration() / 60;
        }

        $sum = 0;
        foreach ($users as $u) {
            $sum += $u['hours'];
        }

        foreach ($users as &$user) {
            $user['quota'] = $this->timeCalculationService->formatQuota($user['hours'], $sum);
        }

        usort($users, $this->sortByName(...));

        return new JsonResponse($users);
    }
}
