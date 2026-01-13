<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Entity\Project;
use App\Model\JsonResponse;
use App\Service\SubticketSyncService;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

final class SyncAllProjectSubticketsAction extends BaseController
{
    #[Route(path: '/projects/syncsubtickets', name: 'syncAllSubtickets_attr', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function __invoke(): JsonResponse
    {
        // Legacy route syncs all projects; mirror behavior by iterating all
        $projects = $this->doctrineRegistry->getRepository(Project::class)->findAll();
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
