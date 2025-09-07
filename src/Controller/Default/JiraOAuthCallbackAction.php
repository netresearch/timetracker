<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Exception;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

final class JiraOAuthCallbackAction extends BaseController
{
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    /**
     * @throws Exception                                                       When database operations fail
     * @throws \Symfony\Component\HttpFoundation\Exception\BadRequestException When query parameters are invalid
     * @throws JiraApiException                                                When Jira API operations fail
     * @throws Exception                                                       When OAuth token operations or API calls fail
     */
    #[\Symfony\Component\Routing\Attribute\Route(path: '/jiraoauthcallback', name: 'jiraOAuthCallback', methods: ['GET'])]
    public function __invoke(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse|\App\Model\Response
    {
        /** @var User $user */
        $user = $this->managerRegistry->getRepository(User::class)->find($this->getUserId($request));

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($request->query->get('tsid'));
        if (!$ticketSystem instanceof TicketSystem) {
            return new \App\Model\Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            $jiraOAuthApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            $oauthToken = $request->query->get('oauth_token');
            $oauthVerifier = $request->query->get('oauth_verifier');
            if (!is_string($oauthToken) || '' === $oauthToken || !is_string($oauthVerifier) || '' === $oauthVerifier) {
                return new \App\Model\Response('Invalid OAuth callback parameters', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            $jiraOAuthApi->fetchOAuthAccessToken($oauthToken, $oauthVerifier);
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new \App\Model\Response($jiraApiException->getMessage());
        }
    }
}
