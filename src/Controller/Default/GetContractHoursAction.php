<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Contract;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Repository\ContractRepository;
use App\Security\ApiToken\RequireScope;
use App\Service\Util\ContractHoursResolver;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function assert;
use function sprintf;

/**
 * Per-weekday working hours from the current user's own contract, for a month.
 *
 * Drives the /ui/month "expected" column: each weekday's expected time comes
 * from the contract valid in the queried month (hours_0 = Sunday … hours_6 =
 * Saturday, matching JS Date.getDay()), with a 5×8h default (8h Mon–Fri, 0 at
 * the weekend) when the user has no contract for that month.
 */
final class GetContractHoursAction extends BaseController
{
    private ContractHoursResolver $contractHoursResolver;

    #[Required]
    public function setContractHoursResolver(ContractHoursResolver $contractHoursResolver): void
    {
        $this->contractHoursResolver = $contractHoursResolver;
    }

    /**
     * @throws Exception When date construction fails
     */
    #[RequireScope('contracts:read')]
    #[Route(path: '/getContractHours', name: '_getContractHours_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse|RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $year = $request->query->get('year');
        $month = $request->query->get('month');

        // Non-numeric params cast to 0, and a negative or out-of-range year/month
        // would make the sprintf() below build a string new DateTime() rejects.
        // Clamp both to a sane range, falling back to the current year/month.
        $filterYear = null !== $year ? (int) $year : (int) date('Y');
        if ($filterYear < 1000 || $filterYear > 9999) {
            $filterYear = (int) date('Y');
        }
        $filterMonth = null !== $month ? (int) $month : (int) date('n');
        if ($filterMonth < 1 || $filterMonth > 12) {
            $filterMonth = (int) date('n');
        }

        $repository = $this->managerRegistry->getRepository(Contract::class);
        assert($repository instanceof ContractRepository);

        // Look up the contract valid on the first of the month. A contract change
        // mid-month is not modelled — the whole month uses the first-of-month
        // contract (acceptable v1 simplification).
        $firstOfMonth = new DateTime(sprintf('%04d-%02d-01', $filterYear, $filterMonth));
        $contract = $repository->findValidContract($user, $firstOfMonth);

        return new JsonResponse($this->buildHours($contract));
    }

    /**
     * @return array{hours_0: float, hours_1: float, hours_2: float, hours_3: float, hours_4: float, hours_5: float, hours_6: float}
     */
    private function buildHours(?Contract $contract): array
    {
        return [
            'hours_0' => $this->contractHoursResolver->weekdayHours($contract, 0),
            'hours_1' => $this->contractHoursResolver->weekdayHours($contract, 1),
            'hours_2' => $this->contractHoursResolver->weekdayHours($contract, 2),
            'hours_3' => $this->contractHoursResolver->weekdayHours($contract, 3),
            'hours_4' => $this->contractHoursResolver->weekdayHours($contract, 4),
            'hours_5' => $this->contractHoursResolver->weekdayHours($contract, 5),
            'hours_6' => $this->contractHoursResolver->weekdayHours($contract, 6),
        ];
    }
}
