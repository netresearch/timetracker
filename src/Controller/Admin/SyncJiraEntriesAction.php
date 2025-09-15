<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\BaseController;
use App\Model\JsonResponse;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use DateTime;
use Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

final class SyncJiraEntriesAction extends BaseController
{
    /**
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When query parameters are invalid
     * @throws Exception                                                       When database operations fail
     * @throws Exception                                                       When date parsing or Jira API operations fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/syncentries/jira', name: 'syncJiraEntries_attr', methods: ['GET'])]
    #[IsGranted("ROLE_ADMIN")]
    public function __invoke(Request $request): JsonResponse
    {

        $from = $request->query->get('from');
        $to = $request->query->get('to');
        null !== $from ? new DateTime((string) $from) : new DateTime()->modify('-3 days');
        null !== $to ? new DateTime((string) $to) : new DateTime();

        // Use current user as admin context if available
        $userId = $this->getUserId($request);
        /** @var \App\Entity\User|null $user */
        $user = $this->doctrineRegistry->getRepository(\App\Entity\User::class)->find($userId);
        /** @var \App\Entity\TicketSystem|null $ticketSystem */
        $ticketSystem = $this->doctrineRegistry->getRepository(\App\Entity\TicketSystem::class)->findOneBy([]);
        $jiraApi = ($user && $ticketSystem) ? $this->jiraOAuthApiFactory->create($user, $ticketSystem) : null;
        if ($jiraApi instanceof \App\Service\Integration\Jira\JiraOAuthApiService) {
            // Mirror earlier behavior: update entries limited window
            // Choose a reasonable limit (null => all pending)
            $jiraApi->updateEntriesJiraWorkLogsLimited();
        }

        $result = (bool) $jiraApi;

        return new JsonResponse(['success' => $result]);
    }

    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }
}
