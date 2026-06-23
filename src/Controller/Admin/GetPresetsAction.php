<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Preset;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\PresetRepository;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetPresetsAction extends BaseController
{
    // Presets are a shared, read-only template source the bulk-entry feature
    // (available to every authenticated user) needs — so reading the list is not
    // admin-gated. Creating/editing/deleting presets stays admin-only via the
    // dedicated Save/Delete actions.
    #[Route(path: '/getAllPresets', name: '_getAllPresets_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): Response|JsonResponse
    {
        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);
        assert($objectRepository instanceof PresetRepository);

        return new JsonResponse($objectRepository->getAllPresets());
    }
}
