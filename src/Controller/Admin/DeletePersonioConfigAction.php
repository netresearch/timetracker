<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Dto\IdDto;
use App\Entity\PersonioConfig;
use App\Exception\EntityAlreadyDeletedException;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use Exception;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function sprintf;

final class DeletePersonioConfigAction extends BaseController
{
    #[Route(path: '/personio-config/delete', name: 'deletePersonioConfig_attr', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] IdDto $idDto): JsonResponse|Error|Response
    {
        try {
            $doctrine = $this->doctrineRegistry;

            $personioConfig = $doctrine->getRepository(PersonioConfig::class)->find($idDto->id);

            $em = $doctrine->getManager();
            if ($personioConfig instanceof PersonioConfig) {
                $em->remove($personioConfig);
                $em->flush();
            } else {
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
