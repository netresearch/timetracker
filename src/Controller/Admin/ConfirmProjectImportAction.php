<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\ProjectImportConfirmDto;
use App\Service\Sync\ProjectImportConfirmationService;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Persist the admin-confirmed ADR-026 P1 project-import rows: create (or link)
 * a TT Project per row with its resolved Customer. ROLE_ADMIN gates admin and
 * PL alike (a PL user carries ROLE_ADMIN — User::getRoles, v4 compat).
 *
 * Shape/format failures (empty rows, blank jiraKey/projectName) are rejected by
 * #[MapRequestPayload] validation (422); business rejections (unknown customer
 * id, blank customer name with no id, invalid prefix, unknown ticket system)
 * surface as 422 from the service.
 */
final readonly class ConfirmProjectImportAction
{
    public function __construct(
        private ProjectImportConfirmationService $projectImportConfirmationService,
    ) {
    }

    #[Route(path: '/project-import/confirm', name: 'project_import_confirm', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(#[MapRequestPayload] ProjectImportConfirmDto $projectImportConfirmDto): JsonResponse
    {
        try {
            $projects = $this->projectImportConfirmationService->confirm($projectImportConfirmDto);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return new JsonResponse(['message' => $invalidArgumentException->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['projects' => $projects]);
    }
}
