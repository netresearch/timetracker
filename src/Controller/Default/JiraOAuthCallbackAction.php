<?php

declare(strict_types=1);

namespace App\Controller\Default;

use App\Controller\BaseController;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Exception\Integration\Jira\JiraApiException;
use App\Model\Response;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use Exception;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Service\Attribute\Required;

use function is_string;

final class JiraOAuthCallbackAction extends BaseController
{
    private JiraOAuthApiFactory $jiraOAuthApiFactory;

    #[Required]
    public function setJiraApiFactory(JiraOAuthApiFactory $jiraOAuthApiFactory): void
    {
        $this->jiraOAuthApiFactory = $jiraOAuthApiFactory;
    }

    /**
     * @throws Exception           When database operations fail
     * @throws BadRequestException When query parameters are invalid
     * @throws JiraApiException    When Jira API operations fail
     * @throws Exception           When OAuth token operations or API calls fail
     */
    #[Route(path: '/jiraoauthcallback', name: 'jiraOAuthCallback', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, #[CurrentUser] ?User $user = null): RedirectResponse|Response
    {
        if (!$user instanceof User) {
            return $this->redirectToRoute('_login');
        }

        /** @var TicketSystem $ticketSystem */
        $ticketSystem = $this->managerRegistry->getRepository(TicketSystem::class)->find($request->query->get('tsid'));
        if (!$ticketSystem instanceof TicketSystem) {
            return new Response('Ticket system not found', \Symfony\Component\HttpFoundation\Response::HTTP_NOT_FOUND);
        }

        try {
            $jiraOAuthApi = $this->jiraOAuthApiFactory->create($user, $ticketSystem);
            $oauthToken = $request->query->get('oauth_token');
            $oauthVerifier = $request->query->get('oauth_verifier');
            if (!is_string($oauthToken) || '' === $oauthToken || !is_string($oauthVerifier) || '' === $oauthVerifier) {
                return new Response('Invalid OAuth callback parameters', \Symfony\Component\HttpFoundation\Response::HTTP_BAD_REQUEST);
            }

            $jiraOAuthApi->fetchOAuthAccessToken($oauthToken, $oauthVerifier);
            $jiraOAuthApi->updateEntriesJiraWorkLogsLimited(1);

            return $this->redirectToRoute('_start');
        } catch (JiraApiException $jiraApiException) {
            return new Response($jiraApiException->getMessage());
        }
    }
}
