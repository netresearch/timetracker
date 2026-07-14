<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\HolidayDeleteDto;
use App\Exception\EntityAlreadyDeletedException;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Doctrine\DBAL\Connection;
use Exception;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

final class DeleteHolidayAction extends BaseController
{
    #[Route(path: '/holiday/delete', name: 'deleteHoliday_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(#[MapRequestPayload] HolidayDeleteDto $holidayDeleteDto): JsonResponse|Error|Response
    {
        try {
            // Delete via DBAL: the ORM cannot manage the entity's DateTime primary key.
            // The day is a validated Y-m-d string (Assert\Date on the DTO).
            /** @var Connection $connection */
            $connection = $this->doctrineRegistry->getConnection();
            $affected = $connection->delete('holidays', ['day' => $holidayDeleteDto->day]);

            if (0 === $affected) {
                throw new EntityAlreadyDeletedException('Already deleted');
            }
        } catch (Exception $exception) {
            $reason = '';
            if (str_contains($exception->getMessage(), 'Integrity constraint violation')) {
                $reason = $this->translate('Other datasets refer to this one.');
            }

            $msg = sprintf($this->translate('Dataset could not be removed. %s'), $reason);

            return new Error($msg, \Symfony\Component\HttpFoundation\Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['success' => true]);
    }
}
