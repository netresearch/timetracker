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

use function is_array;
use function is_string;
use function sprintf;

/**
 * Handles Jira OAuth authentication flow and token management.
 * Separated from JiraOAuthApiService for better maintainability.
 */
class JiraAuthenticationService
{
    private readonly string $oAuthCallbackUrl;

    private string $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';

    private string $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';

    private string $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';

    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
        RouterInterface $router,
        private readonly TokenEncryptionService $tokenEncryptionService,
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
    public function fetchOAuthRequestToken(JiraHttpClientService $jiraHttpClientService): string
    {
        $client = $jiraHttpClientService->getClient('new');
        $response = $client->post(
            $this->getOAuthRequestUrl($jiraHttpClientService->getTicketSystem()),
            ['auth' => 'oauth'],
        );

        $tokens = $this->extractTokens($response);

        if (!isset($tokens['oauth_token'])) {
            throw new JiraApiException('Could not fetch OAuth request token', 500);
        }

        $this->storeToken(
            $jiraHttpClientService->getUser(),
            $jiraHttpClientService->getTicketSystem(),
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
        JiraHttpClientService $jiraHttpClientService,
        string $oAuthRequestToken,
        string $oAuthVerifier,
    ): void {
        $client = $jiraHttpClientService->getClient('request', $oAuthRequestToken);

        $response = $client->post(
            $this->getOAuthAccessUrl($jiraHttpClientService->getTicketSystem()),
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
            $jiraHttpClientService->getUser(),
            $jiraHttpClientService->getTicketSystem(),
            $tokens['oauth_token_secret'],
            $tokens['oauth_token'],
        );
    }

    /**
     * Extracts OAuth tokens from response.
     *
     * @throws JiraApiException when response parsing fails or OAuth problems occur
     *
     * @return array<string, string>
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
            $problem = is_array($tokens['oauth_problem'])
                ? implode(', ', $tokens['oauth_problem'])
                : $tokens['oauth_problem'];
            throw new JiraApiException(sprintf('OAuth problem: %s', $problem), 401);
        }

        // Normalize to string values
        $result = [];
        foreach ($tokens as $key => $value) {
            if (is_string($key)) {
                $result[$key] = is_array($value) ? implode(',', $value) : $value;
            }
        }

        return $result;
    }

    /**
     * Stores OAuth tokens for user and ticket system.
     *
     * @throws Exception when database operations fail
     *
     * @return array{oauth_token_secret: string, oauth_token: string}
     */
    private function storeToken(
        User $user,
        TicketSystem $ticketSystem,
        string $tokenSecret,
        string $accessToken = 'token_request_unfinished',
        bool $avoidConnection = false,
    ): array {
        $objectManager = $this->managerRegistry->getManager();

        $userTicketSystem = $objectManager->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if (!$userTicketSystem instanceof \App\Entity\UserTicketsystem) {
            $userTicketSystem = new UserTicketsystem();
            $userTicketSystem->setUser($user);
            $userTicketSystem->setTicketSystem($ticketSystem);
        }

        // Encrypt tokens before storage
        $encryptedSecret = $this->tokenEncryptionService->encryptToken($tokenSecret);
        $encryptedToken = $this->tokenEncryptionService->encryptToken($accessToken);

        $userTicketSystem->setTokenSecret($encryptedSecret)
            ->setAccessToken($encryptedToken)
            ->setAvoidConnection($avoidConnection)
        ;

        $objectManager->persist($userTicketSystem);
        $objectManager->flush();

        return [
            'oauth_token_secret' => $tokenSecret,
            'oauth_token' => $accessToken,
        ];
    }

    /**
     * Retrieves and decrypts OAuth tokens for user.
     *
     * @throws Exception when token decryption fails (handled internally for legacy tokens)
     *
     * @return array{token: string, secret: string}
     */
    public function getTokens(User $user, TicketSystem $ticketSystem): array
    {
        $objectManager = $this->managerRegistry->getManager();

        $userTicketSystem = $objectManager->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if (!$userTicketSystem instanceof \App\Entity\UserTicketsystem) {
            return ['token' => '', 'secret' => ''];
        }

        try {
            return [
                'token' => $this->tokenEncryptionService->decryptToken($userTicketSystem->getAccessToken()),
                'secret' => $this->tokenEncryptionService->decryptToken($userTicketSystem->getTokenSecret()),
            ];
        } catch (Exception) {
            // Handle legacy unencrypted tokens
            return [
                'token' => $userTicketSystem->getAccessToken(),
                'secret' => $userTicketSystem->getTokenSecret(),
            ];
        }
    }

    /**
     * Deletes stored tokens for user.
     *
     * @throws Exception when database operations fail
     */
    public function deleteTokens(User $user, TicketSystem $ticketSystem): void
    {
        $objectManager = $this->managerRegistry->getManager();

        $userTicketSystem = $objectManager->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $user,
            'ticketSystem' => $ticketSystem,
        ]);

        if ($userTicketSystem instanceof \App\Entity\UserTicketsystem) {
            $objectManager->remove($userTicketSystem);
            $objectManager->flush();
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
     *
     * @throws JiraApiUnauthorizedException always
     */
    public function throwUnauthorizedRedirect(
        TicketSystem $ticketSystem,
        ?Throwable $throwable = null,
    ): never {
        throw new JiraApiUnauthorizedException('Unauthorized. Redirecting to Jira OAuth.', 401, $this->getOAuthAuthUrl($ticketSystem, ''), $throwable);
    }

    /**
     * Authenticates user with ticket system by verifying tokens.
     *
     * @throws JiraApiUnauthorizedException if authentication fails
     * @throws Exception                    when database operations fail
     */
    public function authenticate(User $user, TicketSystem $ticketSystem): void
    {
        if (!$this->checkUserTicketSystem($user, $ticketSystem)) {
            $this->throwUnauthorizedRedirect($ticketSystem);
        }

        $tokens = $this->getTokens($user, $ticketSystem);
        if ('' === $tokens['token'] || '' === $tokens['secret']) {
            $this->throwUnauthorizedRedirect($ticketSystem);
        }
    }
}
