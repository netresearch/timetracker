<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByUserAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/user', name: 'interpretation_user_attr', methods: ['GET'])]
    public function __invoke(Request $request): ModelResponse|JsonResponse
    {
        $request->query->set('user', $this->getUserId($request));
        if (!$this->checkLogin($request)) {
            return $this->getFailedLoginResponse();
        }

        try {
            $entries = $this->getEntries($request);
        } catch (\Exception $exception) {
            $response = new ModelResponse($this->translate($exception->getMessage()));
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_NOT_ACCEPTABLE);
            return $response;
        }

        $users = [];
        foreach ($entries as $entry) {
            $u = $entry->getUser();
            if (!$u) { continue; }

            $uid = $u->getId();
            if (!isset($users[$uid])) {
                $users[$uid] = ['id' => $uid, 'name' => (string) $u->getUsername(), 'hours' => 0, 'quota' => 0];
            }

            $users[$uid]['hours'] += $entry->getDuration() / 60;
        }

        $sum = 0; foreach ($users as $u) { $sum += $u['hours']; }

        foreach ($users as &$user) { $user['quota'] = $this->timeCalculationService->formatQuota($user['hours'], $sum); }

        usort($users, $this->sortByName(...));

        return new JsonResponse($users);
    }
}


