<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Service\SubticketSyncService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

final class SyncAllProjectSubticketsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/projects/syncsubtickets', name: 'syncAllSubtickets_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(Request $request): JsonResponse
    {
        // Legacy route syncs all projects; mirror behavior by iterating all
        $projects = $this->doctrineRegistry->getRepository(\App\Entity\Project::class)->findAll();
        $result = true;
        foreach ($projects as $project) {
            try {
                $this->subticketSyncService->syncProjectSubtickets($project);
            } catch (Throwable) {
                $result = false;
            }
        }

        // Optional JIRA token cleanup is not implemented in current API; no-op for now

        return new JsonResponse(['success' => $result]);
    }

    private SubticketSyncService $subticketSyncService;

    #[Required]
    public function setSubticketSyncService(SubticketSyncService $subticketSyncService): void
    {
        $this->subticketSyncService = $subticketSyncService;
    }

    // No Jira API factory required in current implementation
}
