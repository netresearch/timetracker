<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;
use App\Service\SubticketSyncService;
use App\Service\Integration\Jira\JiraOAuthApiFactory;

final class SyncAllProjectSubticketsAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/projects/syncsubtickets', name: 'syncAllSubtickets_attr', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (false === $this->isPl($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $result = $this->subticketSyncService->syncProjectsSubtickets();

        if (true === $request->query->getBoolean('jira')) {
            $jiraApi = $this->jiraOAuthApiFactory->createApi();
            $jiraApi->revokeAdminToken();
        }

        return new JsonResponse(['success' => $result]);
    }

    private SubticketSyncService $subticketSyncService;
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setSubticketSyncService(SubticketSyncService $svc): void
    {
        $this->subticketSyncService = $svc;
    }

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $factory): void
    {
        $this->jiraOAuthApiFactory = $factory;
    }
}



