<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Interpretation;

use App\Entity\User;
use App\Enum\EntrySource;
use App\Model\JsonResponse;
use App\Model\Response as ModelResponse;
use App\Security\ApiToken\RequireScope;
use App\Service\Util\TimeCalculationService;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

final class GroupByProjectAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[RequireScope('reporting:read')]
    #[Route(path: '/interpretation/project', name: 'interpretation_project_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
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

        $projects = [];
        foreach ($entries as $entry) {
            $projectEntity = $entry->getProject();
            if (null === $projectEntity) {
                continue;
            }

            $pid = $projectEntity->getId();
            if (null === $pid) {
                continue;
            }
            if (!isset($projects[$pid])) {
                $projects[$pid] = ['id' => $pid, 'name' => $projectEntity->getName(), 'hours' => 0, 'agentHours' => 0, 'quota' => 0];
            }

            // ADR-025 §7: human and agent hours are distinct columns, never folded.
            if (EntrySource::AGENT === $entry->getSource()) {
                $projects[$pid]['agentHours'] += $entry->getDuration() / 60;
            } else {
                $projects[$pid]['hours'] += $entry->getDuration() / 60;
            }
        }

        // Quota is human-first: the share is of the human total, agent hours sit
        // beside it without inflating the denominator.
        $sum = 0;
        foreach ($projects as $p) {
            $sum += $p['hours'];
        }

        foreach ($projects as &$project) {
            $project['quota'] = $this->timeCalculationService->formatQuota($project['hours'], $sum);
        }

        usort($projects, $this->sortByName(...));

        return new JsonResponse($projects);
    }
}
