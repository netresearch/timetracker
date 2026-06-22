<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Tracking;

use App\Entity\Entry;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Response\Error;
use App\Util\RequestEntityHelper;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class GetEntryAction extends BaseTrackingController
{
    #[Route(path: '/tracking/entry/{id}', name: 'timetracking_get_attr', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(
        int $id,
        #[CurrentUser]
        ?User $currentUser = null,
    ): Response|JsonResponse|Error {
        $entry = RequestEntityHelper::findById($this->managerRegistry, Entry::class, (string) $id);

        if (!$entry instanceof Entry) {
            return new Error(
                $this->translate('No entry for id.'),
                \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND,
            );
        }

        // A normal developer may only view their own entries; admins and project
        // leads (ROLE_ADMIN — PL carries it for v4 compatibility) may view any.
        if ($entry->getUserId() !== $currentUser?->getId()
            && !$this->isGranted('ROLE_ADMIN')
            && (!$currentUser instanceof User || !$currentUser->getType()->isPl())
        ) {
            return new Error(
                $this->translate('You are not allowed to view this entry.'),
                \Symfony\Component\HttpFoundation\Response::HTTP_FORBIDDEN,
            );
        }

        return new JsonResponse(['data' => $entry->toArray()]);
    }
}
