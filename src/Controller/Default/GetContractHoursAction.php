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
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;
use function sprintf;

/**
 * Per-weekday working hours from the current user's own contract, for a month.
 *
 * Drives the /ui/month "expected" column: each weekday's expected time comes
 * from the contract valid in the queried month (hours_0 = Sunday … hours_6 =
 * Saturday, matching JS Date.getDay()), with an all-8 default when the user has
 * no contract for that month.
 */
final class GetContractHoursAction extends BaseController
{
    /** Default daily hours when the user has no contract covering the month. */
    private const float DEFAULT_HOURS = 8.0;

    /**
     * @throws BadRequestException When query parameters are malformed
     * @throws Exception           When date construction fails
     */
    #[Route(path: '/getContractHours', name: '_getContractHours_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): JsonResponse|RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $year = $request->query->get('year');
        $month = $request->query->get('month');

        $filterYear = null !== $year ? (int) $year : (int) date('Y');
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
        if (!$contract instanceof Contract) {
            return [
                'hours_0' => self::DEFAULT_HOURS,
                'hours_1' => self::DEFAULT_HOURS,
                'hours_2' => self::DEFAULT_HOURS,
                'hours_3' => self::DEFAULT_HOURS,
                'hours_4' => self::DEFAULT_HOURS,
                'hours_5' => self::DEFAULT_HOURS,
                'hours_6' => self::DEFAULT_HOURS,
            ];
        }

        return [
            'hours_0' => (float) $contract->getHours0(),
            'hours_1' => (float) $contract->getHours1(),
            'hours_2' => (float) $contract->getHours2(),
            'hours_3' => (float) $contract->getHours3(),
            'hours_4' => (float) $contract->getHours4(),
            'hours_5' => (float) $contract->getHours5(),
            'hours_6' => (float) $contract->getHours6(),
        ];
    }
}
