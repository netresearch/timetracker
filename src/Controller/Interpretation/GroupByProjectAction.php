<?php
declare(strict_types=1);

namespace App\Controller\Interpretation;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Service\Util\TimeCalculationService;
use Symfony\Component\HttpFoundation\Request;

final class GroupByProjectAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[\Symfony\Component\Routing\Attribute\Route(path: '/interpretation/project', name: 'interpretation_project_attr', methods: ['GET'])]
    public function __invoke(Request $request): ModelResponse|JsonResponse
    {
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

        $projects = [];
        foreach ($entries as $entry) {
            $projectEntity = $entry->getProject();
            if (!$projectEntity) { continue; }
            $pid = $projectEntity->getId();
            if (!isset($projects[$pid])) {
                $projects[$pid] = ['id' => $pid, 'name' => $projectEntity->getName(), 'hours' => 0, 'quota' => 0];
            }
            $projects[$pid]['hours'] += $entry->getDuration() / 60;
        }

        $sum = 0; foreach ($projects as $p) { $sum += $p['hours']; }
        foreach ($projects as &$p) { $p['quota'] = $this->timeCalculationService->formatQuota($p['hours'], $sum); }
        usort($projects, $this->sortByName(...));

        return new JsonResponse(array_values($projects));
    }
}


