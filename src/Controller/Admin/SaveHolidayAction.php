<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\HolidaySaveDto;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SaveHolidayAction extends BaseController
{
    #[Route(path: '/holiday/save', name: 'saveHoliday_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[MapRequestPayload] HolidaySaveDto $holidaySaveDto): Response|JsonResponse|Error
    {
        // The day is a validated Y-m-d string (Assert\Date on the DTO).
        $day = $holidaySaveDto->day;

        // Holidays carry a DateTime primary key, which the ORM UnitOfWork cannot
        // manage (it stringifies the identifier). Persist via DBAL instead
        // (the list endpoint reads via DBAL too — see GetAllHolidaysAction).
        /** @var Connection $connection */
        $connection = $this->doctrineRegistry->getConnection();

        // A holiday is keyed by day and immutable; a duplicate day is a conflict, not an update.
        if (false !== $connection->fetchOne('SELECT day FROM holidays WHERE day = ?', [$day])) {
            return new Error($this->translate('A holiday already exists for this date.'), \Symfony\Component\HttpFoundation\Response::HTTP_CONFLICT);
        }

        try {
            $connection->insert('holidays', ['day' => $day, 'name' => $holidaySaveDto->name]);
        } catch (Exception $exception) {
            $response = new Response($this->translate('Error on save') . ': ' . $exception->getMessage());
            $response->setStatusCode(\Symfony\Component\HttpFoundation\Response::HTTP_INTERNAL_SERVER_ERROR);

            return $response;
        }

        return new JsonResponse([
            'id' => (int) str_replace('-', '', $day),
            'day' => $day,
            'name' => $holidaySaveDto->name,
        ]);
    }
}
