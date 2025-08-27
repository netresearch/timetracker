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

        // Use current user as admin context if available
        $userId = $this->getUserId($request);
        /** @var \App\Entity\User|null $user */
        $user = $this->doctrineRegistry->getRepository(\App\Entity\User::class)->find($userId);
        /** @var \App\Entity\TicketSystem|null $ticketSystem */
        $ticketSystem = $this->doctrineRegistry->getRepository(\App\Entity\TicketSystem::class)->findOneBy([]);
        $jiraApi = ($user && $ticketSystem) ? $this->jiraOAuthApiFactory->create($user, $ticketSystem) : null;
        if ($jiraApi) {
            // Mirror earlier behavior: update entries limited window
            // Choose a reasonable limit (null => all pending)
            $jiraApi->updateEntriesJiraWorkLogsLimited();
        }
        $result = (bool) $jiraApi;

        return new JsonResponse(['success' => $result]);
    }

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $factory): void
    {
        $this->jiraOAuthApiFactory = $factory;
    }
}



