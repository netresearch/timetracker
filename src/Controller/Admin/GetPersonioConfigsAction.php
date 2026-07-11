<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\PersonioConfig;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\PersonioConfigRepository;
use App\Security\ApiToken\RequireScope;
use Exception;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetPersonioConfigsAction extends BaseController
{
    /**
     * @throws Exception
     */
    #[RequireScope('ticketsystems:read')]
    #[Route(path: '/getPersonioConfigs', name: '_getPersonioConfigs_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(PersonioConfig::class);
        assert($objectRepository instanceof PersonioConfigRepository);

        // Row-wrapped ({personio: {…}}) to match the admin grid contract; each
        // row already has the client secret stripped via toSafeArray().
        $rows = [];
        foreach ($objectRepository->findAll() as $personioConfig) {
            $rows[] = ['personio' => $personioConfig->toSafeArray()];
        }

        return new JsonResponse($rows);
    }
}
