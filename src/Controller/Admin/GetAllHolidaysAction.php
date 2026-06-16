<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Model\Response;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function is_string;
use function substr;

final class GetAllHolidaysAction extends BaseController
{
    #[Route(path: '/getAllHolidays', name: '_getAllHolidays_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        // Direct SQL to avoid Holiday entity hydration issues: the entity's DateTime
        // primary key cannot be managed by the ORM UnitOfWork (see GetHolidaysAction).
        /** @var Connection $connection */
        $connection = $this->doctrineRegistry->getConnection();
        $rows = $connection->fetchAllAssociative('SELECT day, name FROM holidays ORDER BY day ASC');

        // Row-wrapped to match the other admin list endpoints ({customer:…}, {account:…}).
        // Holidays have no numeric id; the day (as Ymd) is a stable synthetic id that lets
        // the admin grid track selection/rows, while `day` drives the keyed delete.
        $data = [];
        foreach ($rows as $row) {
            $dayValue = $row['day'] ?? '';
            $nameValue = $row['name'] ?? '';
            $day = substr(is_string($dayValue) ? $dayValue : '', 0, 10);
            $data[] = ['holiday' => [
                'id' => (int) str_replace('-', '', $day),
                'day' => $day,
                'name' => is_string($nameValue) ? $nameValue : '',
            ]];
        }

        return new JsonResponse($data);
    }
}
