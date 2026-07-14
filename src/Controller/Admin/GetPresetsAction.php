<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Preset;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Repository\PresetRepository;
use App\Security\ApiToken\RequireScope;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetPresetsAction extends BaseController
{
    // Presets are a template source the bulk-entry feature (available to every
    // authenticated user) needs — so reading the list is not admin-gated.
    // Creating/editing/deleting presets stays admin-only via the dedicated
    // Save/Delete actions.
    #[RequireScope('presets:read')]
    #[Route(path: '/getAllPresets', name: '_getAllPresets_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(#[CurrentUser] ?User $user = null): JsonResponse|RedirectResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $objectRepository = $this->doctrineRegistry->getRepository(Preset::class);
        assert($objectRepository instanceof PresetRepository);

        // Admins manage presets and need the full list; everyone else gets only
        // the presets for customers they may access, so bulk entry never leaks
        // presets tied to team-restricted customers/projects.
        $presets = $this->isGranted('ROLE_ADMIN')
            ? $objectRepository->getAllPresets()
            : $objectRepository->getPresetsByUser((int) $user->getId());

        return new JsonResponse($presets);
    }
}
