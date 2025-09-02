<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\Security\TokenEncryptionService;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function sprintf;

/**
 * Handles Jira OAuth authentication flow and token management.
 * Separated from JiraOAuthApiService for better maintainability.
 */
class JiraAuthenticationService
{
    private string $oAuthCallbackUrl;
    private string $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';
    private string $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';
    private string $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        private readonly RouterInterface $router,
        private readonly TokenEncryptionService $tokenEncryption,
    ) {
        $this->oAuthCallbackUrl = $router->generate(
            'jiraOAuthCallback',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    /**
     * Initiates OAuth request token flow.
     *
     * @throws JiraApiException
     */
    public function fetchOAuthRequestToken(JiraHttpClientService $clientService): string
    {
        $client = $clientService->getClient('new');
        $response = $client->post(
            $this->getOAuthRequestUrl($clientService->getTicketSystem()),
            ['auth' => 'oauth'],
        );

        $tokens = $this->extractTokens($response);

        if (!isset($tokens['oauth_token'])) {
            throw new JiraApiException('Could not fetch OAuth request token', 500);
        }

        $this->storeToken(
            $clientService->getUser(),
            $clientService->getTicketSystem(),
            '',
            $tokens['oauth_token'],
            true,
        );

        return $tokens['oauth_token'];
    }

    /**
     * Exchanges request token for access token.
     *
     * @throws JiraApiException
     */
    public function fetchOAuthAccessToken(
        JiraHttpClientService $clientService,
        string $oAuthRequestToken,
        string $oAuthVerifier,
    ): void {
        $client = $clientService->getClient('request', $oAuthRequestToken);

        $response = $client->post(
            $this->getOAuthAccessUrl($clientService->getTicketSystem()),
            [
                'auth' => 'oauth',
                'form_params' => ['oauth_verifier' => $oAuthVerifier],
            ],
        );

        $tokens = $this->extractTokens($response);

        if (!isset($tokens['oauth_token'], $tokens['oauth_token_secret'])) {
            throw new JiraApiException('Could not fetch OAuth access token', 500);
        }

        $this->storeToken(
            $clientService->getUser(),
            $clientService->getTicketSystem(),
            $tokens['oauth_token_secret'],
            $tokens['oauth_token'],
        );
    }

    /**
     * Extracts OAuth tokens from response.
     */
    private function extractTokens(ResponseInterface $response): array
    {
        $responseBody = (string) $response->getBody();

        if ('' === $responseBody) {
            throw new JiraApiException('Empty response from Jira OAuth endpoint', 500);
        }

        $tokens = [];
        parse_str($responseBody, $tokens);

        if (isset($tokens['oauth_problem'])) {
            throw new JiraApiException(sprintf('OAuth problem: %s', $tokens['oauth_problem']), 401);
        }

        return $tokens;
    }

    /**
     * Stores OAuth tokens for user and ticket system.
     */
    private function storeToken(
        User $user,
        TicketSystem $ticketSystem,
        string $tokenSecret,
        string $accessToken = 'token_request_unfinished',
        bool $avoidConnection = false,
    ): array {
        $em = $this->managerRegistry->getManager();

        $userTicketSystem = $em->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if (!$userTicketSystem) {
            $userTicketSystem = new UserTicketsystem();
            $userTicketSystem->setUser($user);
            $userTicketSystem->setTicketSystem($ticketSystem);
        }

        // Encrypt tokens before storage
        $encryptedSecret = $this->tokenEncryption->encryptToken($tokenSecret);
        $encryptedToken = $this->tokenEncryption->encryptToken($accessToken);

        $userTicketSystem->setTokenSecret($encryptedSecret)
            ->setAccessToken($encryptedToken)
            ->setAvoidConnection($avoidConnection)
        ;

        $em->persist($userTicketSystem);
        $em->flush();

        return [
            'oauth_token_secret' => $tokenSecret,
            'oauth_token' => $accessToken,
        ];
    }

    /**
     * Retrieves and decrypts OAuth tokens for user.
     */
    public function getTokens(User $user, TicketSystem $ticketSystem): array
    {
        $em = $this->managerRegistry->getManager();

        $userTicketSystem = $em->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if (!$userTicketSystem) {
            return ['token' => '', 'secret' => ''];
        }

        try {
            return [
                'token' => $this->tokenEncryption->decryptToken($userTicketSystem->getAccessToken()),
                'secret' => $this->tokenEncryption->decryptToken($userTicketSystem->getTokenSecret()),
            ];
        } catch (Exception $e) {
            // Handle legacy unencrypted tokens
            return [
                'token' => $userTicketSystem->getAccessToken(),
                'secret' => $userTicketSystem->getTokenSecret(),
            ];
        }
    }

    /**
     * Deletes stored tokens for user.
     */
    public function deleteTokens(User $user, TicketSystem $ticketSystem): void
    {
        $em = $this->managerRegistry->getManager();

        $userTicketSystem = $em->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if ($userTicketSystem) {
            $em->remove($userTicketSystem);
            $em->flush();
        }
    }

    /**
     * Checks if user has valid ticket system configuration.
     */
    public function checkUserTicketSystem(User $user, TicketSystem $ticketSystem): bool
    {
        $userTicketSystem = $this->managerRegistry
            ->getRepository(UserTicketsystem::class)
            ->findOneBy([
                'user' => $user,
                'ticketSystem' => $ticketSystem,
            ])
        ;

        return $userTicketSystem && !$userTicketSystem->getAvoidConnection();
    }

    /**
     * Gets OAuth request URL for ticket system.
     */
    private function getOAuthRequestUrl(TicketSystem $ticketSystem): string
    {
        return $ticketSystem->getUrl() . $this->oAuthRequestUrl;
    }

    /**
     * Gets OAuth access URL for ticket system.
     */
    private function getOAuthAccessUrl(TicketSystem $ticketSystem): string
    {
        return $ticketSystem->getUrl() . $this->oAuthAccessUrl;
    }

    /**
     * Gets OAuth authorization URL with token.
     */
    public function getOAuthAuthUrl(TicketSystem $ticketSystem, string $oAuthToken): string
    {
        return sprintf(
            '%s%s?oauth_token=%s',
            $ticketSystem->getUrl(),
            $this->oAuthAuthUrl,
            $oAuthToken,
        );
    }

    /**
     * Gets OAuth callback URL.
     */
    public function getOAuthCallbackUrl(): string
    {
        return $this->oAuthCallbackUrl;
    }

    /**
     * Throws unauthorized exception with OAuth redirect.
     */
    public function throwUnauthorizedRedirect(
        TicketSystem $ticketSystem,
        ?Throwable $throwable = null,
    ): never {
        throw new JiraApiUnauthorizedException('Unauthorized. Redirecting to Jira OAuth.', 401, $this->getOAuthAuthUrl($ticketSystem, ''), $throwable);
    }
}
