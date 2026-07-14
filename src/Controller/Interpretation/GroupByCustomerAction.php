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

final class GroupByCustomerAction extends BaseInterpretationController
{
    private TimeCalculationService $timeCalculationService;

    #[Required]
    public function setTimeCalculationService(TimeCalculationService $timeCalculationService): void
    {
        $this->timeCalculationService = $timeCalculationService;
    }

    #[RequireScope('reporting:read')]
    #[Route(path: '/interpretation/customer', name: 'interpretation_customer_attr', methods: ['GET'])]
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

        $customers = [];
        foreach ($entries as $entry) {
            $customerEntity = $entry->getCustomer();
            if (null === $customerEntity) {
                continue;
            }

            $cid = $customerEntity->getId();
            if (null === $cid) {
                continue;
            }
            if (!isset($customers[$cid])) {
                $customers[$cid] = ['id' => $cid, 'name' => $customerEntity->getName(), 'hours' => 0, 'agentHours' => 0, 'quota' => 0];
            }

            // ADR-025 §7: human and agent hours are distinct columns, never folded.
            if (EntrySource::AGENT === $entry->getSource()) {
                $customers[$cid]['agentHours'] += $entry->getDuration() / 60;
            } else {
                $customers[$cid]['hours'] += $entry->getDuration() / 60;
            }
        }

        // Quota is human-first: the share is of the human total.
        $sum = 0;
        foreach ($customers as $c) {
            $sum += $c['hours'];
        }

        foreach ($customers as &$customer) {
            $customer['quota'] = $this->timeCalculationService->formatQuota($customer['hours'], $sum);
        }

        usort($customers, $this->sortByName(...));

        return new JsonResponse($customers);
    }
}
