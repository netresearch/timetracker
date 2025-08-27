<?php
declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Service\Attribute\Required;
use App\Service\Integration\Jira\JiraOAuthApiFactory;

final class SyncJiraEntriesAction extends BaseController
{
    #[\Symfony\Component\Routing\Attribute\Route(path: '/syncentries/jira', name: 'syncJiraEntries_attr', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (false === $this->isPl($request)) {
            return new JsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $from = $request->query->get('from');
        $to = $request->query->get('to');
        $fromDate = null !== $from ? new \DateTime((string) $from) : (new \DateTime())->modify('-3 days');
        $toDate = null !== $to ? new \DateTime((string) $to) : new \DateTime();

        $jiraApi = $this->jiraOAuthApiFactory->createApi();
        $result = $jiraApi->syncMonthEntries($fromDate, $toDate);
        $jiraApi->revokeAdminToken();

        return new JsonResponse(['success' => $result]);
    }

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $factory): void
    {
        $this->jiraOAuthApiFactory = $factory;
    }
}



