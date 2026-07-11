<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\DTO\Jira\JiraIssueKeySearchResult;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Service\ClockInterface;
use App\Service\Security\TokenEncryptionService;
use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Override;
use SensitiveParameter;
use Symfony\Component\Routing\RouterInterface;
use Throwable;

use function in_array;
use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function json_decode;
use function parse_url;
use function sprintf;
use function strtolower;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_HOST;

/**
 * Jira Cloud variant of the Jira API service.
 *
 * Jira Cloud does not accept the OAuth 1.0a application-link flow the base
 * class implements (deprecated by Atlassian). This subclass swaps the
 * authentication layer for OAuth 2.0 authorization-code (3LO) with rotating
 * refresh tokens, talks to the tenant through the Atlassian API gateway
 * (api.atlassian.com/ex/jira/{cloudId}), and uses the Cloud-only
 * `search/jql` endpoint. Every worklog/issue operation of the base class
 * works unchanged on top of the Bearer-authenticated client.
 */
class JiraCloudApiService extends JiraOAuthApiService
{
    protected const string AUTH_BASE_URL = 'https://auth.atlassian.com';

    protected const string API_GATEWAY_URL = 'https://api.atlassian.com';

    /** Classic Jira scopes: read/write work objects + refresh-token issuance. */
    protected const string SCOPES = 'read:jira-work write:jira-work offline_access';

    /** Refresh this many seconds before the recorded expiry to absorb clock skew. */
    protected const int EXPIRY_SKEW_SECONDS = 60;

    /** Access token the cached cloud-rest client was built with. */
    private string $cloudRestClientToken = '';

    public function __construct(
        User $user,
        TicketSystem $ticketSystem,
        ManagerRegistry $managerRegistry,
        RouterInterface $router,
        TokenEncryptionService $tokenEncryptionService,
        protected ClockInterface $clock,
    ) {
        parent::__construct($user, $ticketSystem, $managerRegistry, $router, $tokenEncryptionService);
    }

    /**
     * Exchanges the 3LO authorization code for access + refresh tokens and
     * resolves the tenant's cloudId on first authorization.
     *
     * @throws JiraApiException
     */
    public function exchangeAuthorizationCode(#[SensitiveParameter] string $code): void
    {
        $data = $this->requestTokenEndpoint([
            'grant_type' => 'authorization_code',
            'client_id' => $this->getOauth2ClientId(),
            'client_secret' => $this->getOauth2ClientSecret(),
            'code' => $code,
            'redirect_uri' => $this->getOAuthCallbackUrl(),
        ]);

        $this->storeCloudTokens($data['access_token'], $data['refresh_token'], $data['expires_in']);

        $cloudId = $this->ticketSystem->getCloudId();
        if (null === $cloudId || '' === $cloudId) {
            $this->resolveCloudId($data['access_token']);
        }
    }

    /**
     * The OAuth 1.0a token exchange has no meaning on Jira Cloud.
     *
     * @throws JiraApiException
     */
    #[Override]
    public function fetchOAuthAccessToken(#[SensitiveParameter] string $oAuthRequestToken, #[SensitiveParameter] string $oAuthVerifier): void
    {
        throw new JiraApiException(sprintf('Ticket system "%s" is a Jira Cloud system; the OAuth 1.0a callback does not apply to it.', $this->ticketSystem->getName()), 400);
    }

    /**
     * Cloud search uses the dedicated JQL endpoint; the legacy `search/`
     * resource has been removed from Jira Cloud.
     *
     * @param array<int, string> $fields
     */
    #[Override]
    public function searchTicket(string $jql, array $fields, int $limit = 1, int $startAt = 0): mixed
    {
        return $this->post(
            'search/jql',
            [
                'jql' => $jql,
                'fields' => $fields,
                'maxResults' => $limit,
                'startAt' => $startAt,
            ],
        );
    }

    /**
     * Cloud `search/jql` is cursor-based, not offset-based: it ignores
     * `startAt`, never returns `total`, and paginates via
     * `nextPageToken`/`isLast`. The `startAt` loop of the base class would
     * re-fetch the first page forever here, so this override threads the page
     * token instead. `truncated` is only set if the defensive page cap is hit.
     *
     * @throws JiraApiException
     */
    #[Override]
    public function searchIssueKeysWithWorklogs(string $jql, int $limit = 500): JiraIssueKeySearchResult
    {
        $keys = [];
        $nextPageToken = null;
        $truncated = false;

        for ($page = 0;; ++$page) {
            $body = [
                'jql' => $jql,
                'fields' => ['key'],
                'maxResults' => $limit,
            ];
            if (null !== $nextPageToken) {
                $body['nextPageToken'] = $nextPageToken;
            }

            $response = $this->post('search/jql', $body);

            foreach ($this->extractIssueKeys($response) as $key) {
                $keys[] = $key;
            }

            $nextPageToken = $this->extractNextPageToken($response);

            // Cursor exhausted: explicit isLast, or no continuation token.
            if ($this->isLastPage($response) || null === $nextPageToken) {
                break;
            }

            // Defensive stop against a misbehaving API that never terminates.
            if ($page + 1 >= self::MAX_SEARCH_PAGES) {
                $truncated = true;
                break;
            }
        }

        return new JiraIssueKeySearchResult($keys, $truncated);
    }

    /**
     * The cursor for the next `search/jql` page, or null when absent/empty.
     */
    private function extractNextPageToken(mixed $response): ?string
    {
        return is_object($response) && isset($response->nextPageToken) && is_string($response->nextPageToken) && '' !== $response->nextPageToken
            ? $response->nextPageToken
            : null;
    }

    /**
     * Whether `search/jql` flagged this as the final page.
     */
    private function isLastPage(mixed $response): bool
    {
        return is_object($response) && isset($response->isLast) && true === $response->isLast;
    }

    /**
     * Bearer-authenticated client against the tenant's API-gateway base URL.
     * The OAuth1 token modes of the base class do not apply here.
     *
     * @throws JiraApiException
     */
    #[Override]
    protected function getClient(string $tokenMode = 'user', #[SensitiveParameter] ?string $oAuthToken = null): Client
    {
        $accessToken = $this->getValidAccessToken();

        $cloudId = $this->ticketSystem->getCloudId();
        if (null === $cloudId || '' === $cloudId) {
            $this->resolveCloudId($accessToken);
        }

        // One slot, rebuilt when the token rotates — a per-token key would
        // accumulate stale clients in long-running sync processes.
        if ($this->cloudRestClientToken !== $accessToken || !isset($this->clients['cloud-rest'])) {
            $this->clients['cloud-rest'] = $this->createHttpClient([
                'base_uri' => $this->getJiraApiUrl(),
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ]);
            $this->cloudRestClientToken = $accessToken;
        }

        return $this->clients['cloud-rest'];
    }

    /**
     * Tenant API base: Atlassian gateway + cloudId. Pinned to REST v2 —
     * v3 requires Atlassian Document Format for worklog comments, v2 keeps
     * accepting the plain-text comments the base class sends.
     *
     * @throws JiraApiException
     */
    #[Override]
    protected function getJiraApiUrl(): string
    {
        $cloudId = $this->ticketSystem->getCloudId();
        if (null === $cloudId || '' === $cloudId) {
            $this->throwUnauthorizedRedirect();
        }

        return static::API_GATEWAY_URL . '/ex/jira/' . $cloudId . '/rest/api/2/';
    }

    /**
     * Cloud redirect URIs must match the registered callback exactly, so no
     * `?tsid=` may be appended — the ticket system rides in `state` instead.
     */
    #[Override]
    protected function getOAuthCallbackUrl(): string
    {
        return $this->oAuthCallbackUrl;
    }

    /**
     * Builds the 3LO authorize URL and throws the redirect exception the
     * frontend turns into a "Please authorize" link.
     *
     * @throws JiraApiUnauthorizedException
     * @throws JiraApiException
     */
    #[Override]
    protected function throwUnauthorizedRedirect(?Throwable $throwable = null): never
    {
        $authorizeUrl = static::AUTH_BASE_URL . '/authorize?' . http_build_query([
            'audience' => 'api.atlassian.com',
            'client_id' => $this->getOauth2ClientId(),
            'scope' => static::SCOPES,
            'redirect_uri' => $this->getOAuthCallbackUrl(),
            'state' => $this->createStateCodec()->encode((int) $this->user->getId(), (int) $this->ticketSystem->getId()),
            'response_type' => 'code',
            'prompt' => 'consent',
        ]);

        $message = '401 - Unauthorized. Please authorize: ' . $authorizeUrl;
        throw new JiraApiUnauthorizedException($message, 401, $authorizeUrl, $throwable);
    }

    /**
     * Returns a decrypted, non-expired access token — refreshing it via the
     * rotating refresh token when it is at or past its recorded expiry.
     *
     * @throws JiraApiException
     */
    protected function getValidAccessToken(): string
    {
        $userTicketSystem = $this->getUserTicketSystem();
        if (!$userTicketSystem instanceof UserTicketsystem) {
            $this->throwUnauthorizedRedirect();
        }

        // Read through the repository-loaded row (single source of truth) —
        // the User entity's collection can lag behind a store in the same
        // process (fresh first authorization, rotated refresh).
        $accessToken = $this->decryptStored($userTicketSystem->getAccessToken());
        if ('' === $accessToken) {
            $this->throwUnauthorizedRedirect();
        }

        $expiresAt = $userTicketSystem->getTokenExpiresAt();
        if ($expiresAt instanceof DateTimeImmutable
            && $this->clock->now()->getTimestamp() >= $expiresAt->getTimestamp() - static::EXPIRY_SKEW_SECONDS
        ) {
            return $this->refreshAccessToken($userTicketSystem);
        }

        return $accessToken;
    }

    /**
     * Refreshes the access token. Atlassian rotates refresh tokens: the old
     * one is consumed and BOTH returned tokens must be stored. A rejected
     * refresh (revoked/expired grant) clears the stored tokens and restarts
     * the authorize flow.
     *
     * @throws JiraApiException
     */
    protected function refreshAccessToken(UserTicketsystem $userTicketSystem): string
    {
        $storedRefreshToken = $userTicketSystem->getRefreshToken();
        $refreshToken = null !== $storedRefreshToken && '' !== $storedRefreshToken
            ? $this->decryptStored($storedRefreshToken)
            : '';

        if ('' === $refreshToken) {
            $this->throwUnauthorizedRedirect();
        }

        try {
            $data = $this->requestTokenEndpoint([
                'grant_type' => 'refresh_token',
                'client_id' => $this->getOauth2ClientId(),
                'client_secret' => $this->getOauth2ClientSecret(),
                'refresh_token' => $refreshToken,
            ]);
        } catch (JiraApiException $jiraApiException) {
            // Only a definitive rejection means the grant is gone (revoked,
            // rotated away, or expired) — clear and force re-authorization.
            // Transient failures (network, 5xx, bad JSON) keep the grant so
            // the next sync can retry the refresh.
            if (in_array($jiraApiException->getCode(), [400, 401, 403], true)) {
                $this->storeCloudTokens('', '', 0);
                $this->throwUnauthorizedRedirect($jiraApiException);
            }

            throw $jiraApiException;
        }

        $this->storeCloudTokens($data['access_token'], $data['refresh_token'], $data['expires_in']);

        return $data['access_token'];
    }

    /**
     * POSTs to the Atlassian token endpoint and validates the response shape.
     *
     * @param array<string, string> $payload
     *
     * @throws JiraApiException
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    protected function requestTokenEndpoint(#[SensitiveParameter] array $payload): array
    {
        try {
            $response = $this->getAuthClient()->request('POST', '/oauth/token', ['json' => $payload]);
            $data = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $guzzleException) {
            throw new JiraApiException('Atlassian token endpoint rejected the request: ' . $guzzleException->getMessage(), $guzzleException->getCode(), null, $guzzleException);
        } catch (JsonException $jsonException) {
            throw new JiraApiException('Atlassian token endpoint returned invalid JSON.', 502, null, $jsonException);
        }

        if (!is_array($data)
            || !is_string($data['access_token'] ?? null) || '' === $data['access_token']
            || !is_string($data['refresh_token'] ?? null) || '' === $data['refresh_token']
            || !is_int($data['expires_in'] ?? null)
        ) {
            throw new JiraApiException('Atlassian token endpoint returned an unexpected response.', 502);
        }

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => $data['expires_in'],
        ];
    }

    /**
     * Resolves the tenant's cloudId from the sites the user authorized and
     * persists it on the ticket system.
     *
     * @throws JiraApiException
     */
    protected function resolveCloudId(#[SensitiveParameter] string $accessToken): void
    {
        try {
            $response = $this->createHttpClient([
                'base_uri' => static::API_GATEWAY_URL,
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                ],
            ])->request('GET', '/oauth/token/accessible-resources');
            $resources = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $guzzleException) {
            throw new JiraApiException('Could not list accessible Atlassian sites: ' . $guzzleException->getMessage(), $guzzleException->getCode(), null, $guzzleException);
        } catch (JsonException $jsonException) {
            throw new JiraApiException('Atlassian accessible-resources returned invalid JSON.', 502, null, $jsonException);
        }

        $wantedHost = strtolower((string) parse_url($this->ticketSystem->getUrl(), PHP_URL_HOST));

        if (is_array($resources)) {
            foreach ($resources as $resource) {
                if (!is_array($resource)) {
                    continue;
                }
                if (!is_string($resource['id'] ?? null)) {
                    continue;
                }
                if (!is_string($resource['url'] ?? null)) {
                    continue;
                }
                if (strtolower((string) parse_url($resource['url'], PHP_URL_HOST)) === $wantedHost) {
                    $this->ticketSystem->setCloudId($resource['id']);
                    $objectManager = $this->managerRegistry->getManager();
                    $objectManager->persist($this->ticketSystem);
                    $objectManager->flush();

                    return;
                }
            }
        }

        throw new JiraApiException(sprintf('None of the Atlassian sites you authorized matches "%s" — check the ticket system URL or re-authorize with the right account.', $this->ticketSystem->getUrl()), 400);
    }

    /**
     * Stores the rotated token set, encrypted at rest, with the absolute
     * access-token expiry.
     */
    protected function storeCloudTokens(
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $refreshToken,
        int $expiresIn,
    ): void {
        $repository = $this->managerRegistry->getRepository(UserTicketsystem::class);
        $userTicketSystem = $repository->findOneBy([
            'user' => $this->user,
            'ticketSystem' => $this->ticketSystem,
        ]);

        if (!$userTicketSystem instanceof UserTicketsystem) {
            $userTicketSystem = new UserTicketsystem();
            $userTicketSystem->setUser($this->user)
                ->setTicketSystem($this->ticketSystem);
        }

        $userTicketSystem->setAccessToken($this->tokenEncryptionService->encryptToken($accessToken))
            ->setTokenSecret('')
            ->setRefreshToken($this->tokenEncryptionService->encryptToken($refreshToken))
            ->setTokenExpiresAt($this->clock->now()->modify(sprintf('+%d seconds', max(0, $expiresIn))))
            ->setAvoidConnection(false);

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($userTicketSystem);
        $objectManager->flush();
    }

    protected function getUserTicketSystem(): ?UserTicketsystem
    {
        $result = $this->managerRegistry->getRepository(UserTicketsystem::class)->findOneBy([
            'user' => $this->user,
            'ticketSystem' => $this->ticketSystem,
        ]);

        return $result instanceof UserTicketsystem ? $result : null;
    }

    protected function createStateCodec(): CloudOAuthStateCodec
    {
        return new CloudOAuthStateCodec($this->tokenEncryptionService, $this->clock);
    }

    /**
     * Client for auth.atlassian.com (token endpoint).
     *
     * @throws JiraApiException
     */
    protected function getAuthClient(): Client
    {
        if (isset($this->clients['cloud-auth'])) {
            return $this->clients['cloud-auth'];
        }

        $this->clients['cloud-auth'] = $this->createHttpClient([
            'base_uri' => static::AUTH_BASE_URL,
            'headers' => ['Accept' => 'application/json'],
        ]);

        return $this->clients['cloud-auth'];
    }

    /**
     * Test seam: all Guzzle clients of the Cloud flow are created here.
     *
     * @param array<string, mixed> $config
     */
    protected function createHttpClient(array $config): Client
    {
        return new Client($config);
    }

    /**
     * @throws JiraApiException
     */
    protected function getOauth2ClientId(): string
    {
        $clientId = $this->ticketSystem->getOauth2ClientId();
        if (null === $clientId || '' === $clientId) {
            throw new JiraApiException(sprintf('Ticket system "%s" is set to Jira Cloud but has no OAuth2 client id configured.', $this->ticketSystem->getName()), 400);
        }

        return $clientId;
    }

    protected function getOauth2ClientSecret(): string
    {
        return $this->ticketSystem->getOauth2ClientSecret() ?? '';
    }
}
