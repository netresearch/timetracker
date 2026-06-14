<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\TicketSystemRepository;
use Exception;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function array_key_exists;
use function assert;
use function is_array;

final class GetTicketSystemsAction extends BaseController
{
    /**
     * @throws Exception
     */
    #[Route(path: '/getTicketSystems', name: '_getTicketSystems_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(TicketSystem::class);
        assert($objectRepository instanceof TicketSystemRepository);
        $ticketSystems = $objectRepository->getAllTicketSystems();

        // Even though the route is ROLE_ADMIN-only, there is no reason to ship
        // OAuth secrets and passwords to every admin browser/proxy on each grid
        // load; they are only ever needed server-side.
        foreach ($ticketSystems as &$row) {
            if (is_array($row) && array_key_exists('ticketSystem', $row) && is_array($row['ticketSystem'])) {
                foreach (TicketSystem::SECRET_KEYS as $secretKey) {
                    unset($row['ticketSystem'][$secretKey]);
                }
            }
        }

        unset($row);

        return new JsonResponse($ticketSystems);
    }
}
