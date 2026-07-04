<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Entity\User;
use App\Model\JsonResponse;
use App\Model\Response;
use App\Repository\ProjectRepository;
use App\Security\ApiToken\RequireScope;
use Exception;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function assert;

final class GetAllProjectsAction extends BaseController
{
    /**
     * @throws BadRequestException      When query parameters are invalid
     * @throws Exception                When database operations fail
     * @throws InvalidArgumentException When customer ID parameter is invalid
     */
    #[RequireScope('projects:read')]
    #[Route(path: '/getAllProjects', name: '_getAllProjects_attr', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): RedirectResponse|Response|JsonResponse
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        $customerId = (int) $request->query->get('customer');
        $objectRepository = $this->managerRegistry->getRepository(Project::class);
        assert($objectRepository instanceof ProjectRepository);
        /** @var array<int, Project> $result */
        $result = $customerId > 0 ? $objectRepository->findByCustomer($customerId) : $objectRepository->findAll();

        // "Last activity" (date of the most recent booking) is for the admin overview,
        // which lists every project unfiltered; skip the aggregate on the customer-filtered
        // entry-form path, which doesn't show the column.
        $lastActivity = $customerId > 0 ? [] : $objectRepository->lastActivityBy('project_id');

        $data = [];
        foreach ($result as $project) {
            if ($project instanceof Project) {
                $row = $project->toArray();
                $row['last_activity'] = $lastActivity[(int) $project->getId()] ?? null;
                $data[] = ['project' => $row];
            }
        }

        return new JsonResponse($data);
    }
}
