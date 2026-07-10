<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\DTO\Jira\JiraIssue;
use App\DTO\Jira\JiraIssueKeySearchResult;
use App\DTO\Jira\JiraSearchResult;
use App\DTO\Jira\JiraUserIdentity;
use App\DTO\Jira\JiraWorkLog;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use App\Repository\EntryRepository;
use App\Service\Security\TokenEncryptionService;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Psr\Http\Message\ResponseInterface;
use SensitiveParameter;
use stdClass;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;
use UnexpectedValueException;

use function assert;
use function count;
use function is_array;
use function is_numeric;
use function is_object;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Jira OAuth API service responsible for OAuth handshake and Jira REST operations.
 */
class JiraOAuthApiService
{
    protected string $oAuthCallbackUrl;

    protected string $jiraApiUrl = '/rest/api/latest/';

    protected string $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';

    protected string $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';

    protected string $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';

    /** @var Client[] */
    protected array $clients = [];

    public function __construct(
        protected User $user,
        protected TicketSystem $ticketSystem,
        protected ManagerRegistry $managerRegistry,
        RouterInterface $router,
        protected TokenEncryptionService $tokenEncryptionService,
    ) {
        $this->oAuthCallbackUrl = $router->generate('jiraOAuthCallback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Returns a HTTP client preconfigured for OAuth request token retrieval.
     *
     * @throws JiraApiException
     */
    protected function getFetchRequestTokenClient(): Client
    {
        return $this->getClient('new');
    }

    /**
     * Returns a HTTP client preconfigured for OAuth access token retrieval.
     *
     * @throws JiraApiException
     */
    protected function getFetchAccessTokenClient(#[SensitiveParameter] string $oAuthRequestToken): Client
    {
        return $this->getClient('request', $oAuthRequestToken);
    }

    /**
     * Returns a HTTP client preconfigured for OAuth communication.
     *
     * @param string      $tokenMode  user|new|request
     * @param string|null $oAuthToken Request token when supplied
     *
     * @throws JiraApiException
     */
    protected function getClient(string $tokenMode = 'user', #[SensitiveParameter] ?string $oAuthToken = null): Client
    {
        if ('user' === $tokenMode) {
            $oAuthTokenSecret = $this->getTokenSecret();
            $oAuthToken = $this->getToken();
            if ('' === $oAuthToken && '' === $oAuthTokenSecret) {
                $this->throwUnauthorizedRedirect();
            }
        } elseif ('new' === $tokenMode) {
            $oAuthToken = '';
            $oAuthTokenSecret = '';
        } elseif ('request' === $tokenMode) {
            $oAuthTokenSecret = '';
        } else {
            throw new UnexpectedValueException('Invalid token mode: ' . $tokenMode);
        }

        $key = $oAuthToken . $oAuthTokenSecret;

        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $curlHandler = new CurlHandler();
        $handlerStack = HandlerStack::create($curlHandler);

        $oauth1 = new Oauth1([
            'consumer_key' => $this->getOAuthConsumerKey(),
            'consumer_secret' => $this->getOAuthConsumerSecret(),
            'token_secret' => $oAuthTokenSecret,
            'token' => $oAuthToken,
            'request_method' => Oauth1::REQUEST_METHOD_QUERY,
            'signature_method' => Oauth1::SIGNATURE_METHOD_RSA,
            'private_key_file' => $this->getPrivateKeyFile(),
            'private_key_passphrase' => '',
        ]);
        $handlerStack->push($oauth1);

        $this->clients[$key] = new Client([
            'base_uri' => $this->getJiraApiUrl(),
            'handler' => $handlerStack,
            'auth' => 'oauth',
        ]);

        return $this->clients[$key];
    }

    /**
     * Returns path to private key file.
     *
     * @throws JiraApiException
     */
    protected function getPrivateKeyFile(): string
    {
        $certificate = $this->getOAuthConsumerSecret();

        if (is_file($certificate)) {
            return $certificate;
        }

        $keyFileHeader = '-----BEGIN PRIVATE KEY-----';

        if (str_starts_with($certificate, $keyFileHeader)) {
            return $this->getTempKeyFile($certificate);
        }

        throw new JiraApiException('Invalid certificate, fix your certificate information in ticket system settings for: "' . $this->ticketSystem->getName() . '"', 1541160391);
    }

    /**
     * Returns temp key file name.
     */
    protected function getTempKeyFile(string $certificate): string
    {
        $keyFile = $this->getTempFile();
        file_put_contents($keyFile, $certificate);

        return $keyFile;
    }

    /**
     * Returns temp file name.
     */
    protected function getTempFile(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'TTT');
        if (false === $tmp) {
            throw new JiraApiException('Failed to create temporary file');
        }

        return $tmp;
    }

    /**
     * Fetches and Stores Jira access token.
     *
     * @throws JiraApiException
     */
    public function fetchOAuthAccessToken(#[SensitiveParameter] string $oAuthRequestToken, #[SensitiveParameter] string $oAuthVerifier): void
    {
        try {
            if ('denied' === $oAuthVerifier) {
                $this->deleteTokens();
            } else {
                $response = $this->getFetchAccessTokenClient($oAuthRequestToken)->post(
                    $this->getOAuthAccessUrl() . '?oauth_verifier=' . urlencode($oAuthVerifier),
                );
                $this->extractTokens($response);
            }
        } catch (Throwable $throwable) {
            throw new JiraApiException($throwable->getMessage(), (int) $throwable->getCode());
        }
    }

    /**
     * Delete stored tokens.
     */
    protected function deleteTokens(): void
    {
        $this->storeToken('', '', true);
    }

    /**
     * Fetches request token.
     *
     * @throws JiraApiException
     */
    protected function fetchOAuthRequestToken(): string
    {
        try {
            $response = $this->getFetchRequestTokenClient()->post(
                $this->getOAuthRequestUrl() . '?oauth_callback=' . urlencode($this->getOAuthCallbackUrl()),
            );

            $tokenData = $this->extractTokens($response);

            return $this->getOAuthAuthUrl($tokenData['oauth_token']);
        } catch (Throwable $throwable) {
            throw new JiraApiException($throwable->getMessage(), (int) $throwable->getCode(), null, $throwable);
        }
    }

    /**
     * @throws JiraApiException
     *
     * @return array{oauth_token_secret:string,oauth_token:string}
     */
    protected function extractTokens(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        $tokenRaw = [];
        parse_str($body, $tokenRaw);

        if ([] === $tokenRaw) {
            throw new JiraApiException('An unknown error occurred while requesting OAuth token.', 1541147716);
        }

        $secret = is_string($tokenRaw['oauth_token_secret'] ?? null) ? $tokenRaw['oauth_token_secret'] : '';
        $access = is_string($tokenRaw['oauth_token'] ?? null) ? $tokenRaw['oauth_token'] : '';

        return $this->storeToken($secret, $access);
    }

    /**
     *  Updates Jira work log entries to all user entries and the set ticket system.
     */
    public function updateAllEntriesJiraWorkLogs(): void
    {
        $this->updateEntriesJiraWorkLogsLimited();
    }

    /**
     * Updates Jira work log entries to a set number of user entries and the set ticket system
     * (entries ordered by date, time desc).
     */
    public function updateEntriesJiraWorkLogsLimited(?int $entryLimit = null): void
    {
        if (!$this->checkUserTicketSystem()) {
            return;
        }

        $objectManager = $this->managerRegistry->getManager();
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        assert($objectRepository instanceof EntryRepository);
        $entries = $objectRepository->findByUserAndTicketSystemToSync((int) $this->user->getId(), (int) $this->ticketSystem->getId(), $entryLimit ?? 50);

        foreach ($entries as $entry) {
            try {
                $this->updateEntryJiraWorkLog($entry);
                $objectManager->persist($entry);
            } catch (Exception) {
                // Best effort: one entry's failed worklog sync must not abort
                // the batch; the finally still flushes the others.
            } finally {
                $objectManager->flush();
            }
        }
    }

    /**
     * Create or update Jira work log entry.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function updateEntryJiraWorkLog(Entry $entry): void
    {
        $sTicket = $entry->getTicket();
        if ('' === $sTicket || '0' === $sTicket) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        if (!$this->doesTicketExist($sTicket)) {
            return;
        }

        if (0 === $entry->getDuration()) {
            // delete possible old work log entry
            $this->deleteEntryJiraWorkLog($entry);

            // without duration we do not add any work log entry as Jira complains
            return;
        }

        if (null !== $entry->getWorklogId() && !$this->doesWorkLogExist($sTicket, $entry->getWorklogId())) {
            $entry->setWorklogId(null);
        }

        $arData = [
            'comment' => $this->getTicketSystemWorkLogComment($entry),
            'started' => $this->getTicketSystemWorkLogStartDate($entry),
            'timeSpentSeconds' => $entry->getDuration() * 60,
        ];

        $workLogId = $entry->getWorklogId();
        if (null !== $workLogId) {
            $response = $this->put(
                sprintf(JiraWorkLogService::WORKLOG_ITEM_URL_TEMPLATE, $sTicket, $workLogId),
                $arData,
            );
        } else {
            $response = $this->post(sprintf('issue/%s/worklog', $sTicket), $arData);
        }

        $workLog = JiraWorkLog::fromApiResponse($response);

        if (!$workLog->hasValidId()) {
            throw new JiraApiException('Unexpected response from Jira when updating worklog', 500);
        }

        $entry->setWorklogId($workLog->id);
        $entry->setSyncedToTicketsystem(true);
    }

    /**
     * Creates a work log entry for the given entry in JIRA.
     * This is an alias for updateEntryJiraWorkLog to maintain backward compatibility.
     */
    public function createEntryJiraWorkLog(Entry $entry): void
    {
        $this->updateEntryJiraWorkLog($entry);
    }

    /**
     * Removes Jira workLog entry.
     *
     * @throws JiraApiException
     */
    public function deleteEntryJiraWorkLog(Entry $entry): void
    {
        $sTicket = $entry->getTicket();
        if ('' === $sTicket || '0' === $sTicket) {
            return;
        }

        $worklogId = $entry->getWorklogId();
        if (null === $worklogId || $worklogId <= 0) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        try {
            $workLogId = $entry->getWorklogId() ?? 0;
            $this->delete(sprintf(
                JiraWorkLogService::WORKLOG_ITEM_URL_TEMPLATE,
                $sTicket,
                $workLogId,
            ));

            $entry->setWorklogId(null);
        } catch (JiraApiInvalidResourceException) {
            // The worklog is already gone on the Jira side — nothing to delete.
        }
    }

    /**
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function createTicket(Entry $entry): mixed
    {
        $project = $entry->getProject();
        if (!$project instanceof Project) {
            throw new JiraApiException('Entry has no project', 400);
        }

        return $this->post(
            'issue/',
            [
                'fields' => [
                    'project' => [
                        'key' => $project->getInternalJiraProjectKey() ?? '',
                    ],
                    'summary' => $entry->getTicket(),
                    'description' => $entry->getTicketSystemIssueLink(),
                    'issuetype' => [
                        'name' => 'Task',
                    ],
                ],
            ],
        );
    }

    /**
     * we use POST to support very large queries.
     *
     * @param array<int, string> $fields
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function searchTicket(string $jql, array $fields, int $limit = 1): mixed
    {
        return $this->post(
            'search/',
            [
                'jql' => $jql,
                'fields' => $fields,
                'maxResults' => $limit,
            ],
        );
    }

    /**
     * Checks existence of a ticket in Jira.
     *
     * @throws JiraApiException
     */
    public function doesTicketExist(string $sTicket): bool
    {
        return $this->doesResourceExist(sprintf('issue/%s', $sTicket));
    }

    /**
     * Get an array of ticket numbers that are subtickets of the given issue.
     *
     * @return list<string>
     */
    public function getSubtickets(string $sTicket): array
    {
        if (!$this->doesTicketExist($sTicket)) {
            return [];
        }

        $response = $this->get('issue/' . $sTicket);

        // Convert response to DTO
        if (!is_object($response)) {
            return [];
        }

        $ticket = JiraIssue::fromApiResponse($response);
        $subtickets = $ticket->subtaskKeys;

        // Check for epic type tickets
        if ($ticket->isEpic()) {
            return array_merge($subtickets, $this->getEpicSubtickets($sTicket));
        }

        return $subtickets;
    }

    /**
     * Reads all worklogs of one issue (ADR-023 read path 3).
     *
     * @throws JiraApiException
     *
     * @return list<JiraWorkLog>
     */
    public function getIssueWorklogs(string $issueKey): array
    {
        $workLogs = [];
        $startAt = 0;

        do {
            $response = $this->get(sprintf('issue/%s/worklog?maxResults=1000&startAt=%d', $issueKey, $startAt));

            if (!is_object($response) || !isset($response->worklogs) || !is_array($response->worklogs)) {
                return $workLogs;
            }

            $pageSize = 0;
            foreach ($response->worklogs as $workLog) {
                ++$pageSize;
                if (is_object($workLog)) {
                    $workLogs[] = JiraWorkLog::fromApiResponse($workLog);
                }
            }

            $total = isset($response->total) && is_numeric($response->total) ? (int) $response->total : null;
            $startAt += $pageSize;
        } while ($pageSize > 0 && null !== $total && $startAt < $total);

        return $workLogs;
    }

    /**
     * JQL search returning issue keys only, with explicit truncation reporting (ADR-023 read path 2).
     *
     * @throws JiraApiException
     */
    public function searchIssueKeysWithWorklogs(string $jql, int $limit = 500): JiraIssueKeySearchResult
    {
        $response = $this->searchTicket($jql, ['key'], $limit);

        $keys = [];
        if (is_object($response) && isset($response->issues) && is_array($response->issues)) {
            foreach ($response->issues as $issue) {
                if (is_object($issue) && isset($issue->key) && is_string($issue->key)) {
                    $keys[] = $issue->key;
                }
            }
        }

        $total = is_object($response) && isset($response->total) && is_numeric($response->total) ? (int) $response->total : null;
        $truncated = count($keys) >= $limit || (null !== $total && $total > count($keys));

        return new JiraIssueKeySearchResult($keys, $truncated);
    }

    /**
     * The Jira account behind the current token (GET myself) — for author filtering.
     *
     * @throws JiraApiException
     */
    public function getMyself(): JiraUserIdentity
    {
        $response = $this->get('myself');

        return JiraUserIdentity::fromApiResponse(is_object($response) ? $response : new stdClass());
    }

    /**
     * Single worklog read — the lease comparand (ADR-023 §1). Null when the worklog is gone.
     *
     * @throws JiraApiException
     */
    public function getIssueWorklog(string $issueKey, int $worklogId): ?JiraWorkLog
    {
        try {
            $response = $this->get(sprintf(JiraWorkLogService::WORKLOG_ITEM_URL_TEMPLATE, $issueKey, $worklogId));
        } catch (JiraApiInvalidResourceException) {
            return null;
        }

        return is_object($response) ? JiraWorkLog::fromApiResponse($response) : null;
    }

    /**
     * Collects the issues linked to an epic including their nested subtasks.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     *
     * @return list<string>
     */
    private function getEpicSubtickets(string $sTicket): array
    {
        $epicSearchResponse = $this->searchTicket('"Epic Link" = ' . $sTicket, ['key', 'subtasks'], 100);

        if (!is_object($epicSearchResponse)) {
            return [];
        }

        $subtickets = [];
        $epicSearchResult = JiraSearchResult::fromApiResponse($epicSearchResponse);

        foreach ($epicSearchResult->issues as $epicSubtask) {
            if (null !== $epicSubtask->key) {
                $subtickets[] = $epicSubtask->key;

                // Add nested subtasks
                foreach ($epicSubtask->subtaskKeys as $nestedKey) {
                    $subtickets[] = $nestedKey;
                }
            }
        }

        return $subtickets;
    }

    /**
     * Checks existence of a work log entry in Jira.
     *
     * @throws JiraApiException
     */
    protected function doesWorkLogExist(string $sTicket, int $workLogId): bool
    {
        return $this->doesResourceExist(sprintf(JiraWorkLogService::WORKLOG_ITEM_URL_TEMPLATE, $sTicket, $workLogId));
    }

    /**
     * Checks existence of a Jira resource.
     *
     * @throws JiraApiException
     */
    protected function doesResourceExist(string $url): bool
    {
        try {
            $this->get($url);
        } catch (JiraApiInvalidResourceException) {
            return false;
        }

        return true;
    }

    /**
     * Execute GET request and return response as simple object.
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function get(string $url): mixed
    {
        return $this->getResponse('GET', $url);
    }

    /**
     * Execute POST request and return response as simple object.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function post(string $url, array $data = []): object
    {
        return $this->getResponse('POST', $url, $data);
    }

    /**
     * Execute PUT request and return response as simple object.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function put(string $url, array $data = []): object
    {
        return $this->getResponse('PUT', $url, $data);
    }

    /**
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function delete(string $url): object
    {
        return $this->getResponse('DELETE', $url);
    }

    /**
     * Get Response of Jira-API request.
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function getResponse(string $method, string $url, array $data = []): object
    {
        $additionalParameter = [];
        if ([] !== $data) {
            $additionalParameter = [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
            ];
        }

        try {
            $response = $this->getClient()->request($method, $url, $additionalParameter);
        } catch (GuzzleException $guzzleException) {
            if (401 === $guzzleException->getCode()) {
                $this->throwUnauthorizedRedirect();
            } elseif (404 === $guzzleException->getCode()) {
                $message = '404 - Resource is not available: (' . $url . ')';
                throw new JiraApiInvalidResourceException($message, 404, null, $guzzleException);
            } else {
                throw new JiraApiException('Unknown Guzzle exception: ' . $guzzleException->getMessage(), $guzzleException->getCode(), null, $guzzleException);
            }
        }

        $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);
        if (!is_object($decoded)) {
            throw new JiraApiException('Unexpected non-object response from Jira API', 500);
        }

        return $decoded;
    }

    /**
     * Like getResponse(), for endpoints returning a JSON array (e.g. POST worklog/list).
     *
     * @param array<string, mixed> $data
     *
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     *
     * @return list<object>
     */
    protected function getResponseArray(string $url, array $data = []): array
    {
        $additionalParameter = [];
        if ([] !== $data) {
            $additionalParameter = [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data),
            ];
        }

        try {
            $response = $this->getClient()->request('POST', $url, $additionalParameter);
        } catch (GuzzleException $guzzleException) {
            if (401 === $guzzleException->getCode()) {
                $this->throwUnauthorizedRedirect();
            } elseif (404 === $guzzleException->getCode()) {
                $message = '404 - Resource is not available: (' . $url . ')';
                throw new JiraApiInvalidResourceException($message, 404, null, $guzzleException);
            } else {
                throw new JiraApiException('Unknown Guzzle exception: ' . $guzzleException->getMessage(), $guzzleException->getCode(), null, $guzzleException);
            }
        }

        $decoded = json_decode((string) $response->getBody(), false, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_filter($decoded, is_object(...))) : [];
    }

    /**
     * Stores access token and token secret to Database.
     *
     * @return array{oauth_token_secret:string,oauth_token:string}
     */
    protected function storeToken(
        #[SensitiveParameter] string $tokenSecret,
        #[SensitiveParameter] string $accessToken = 'token_request_unfinished',
        bool $avoidConnection = false,
    ): array {
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

        // Encrypt at rest (AES-256-GCM); reads decrypt in getToken()/getTokenSecret().
        $userTicketSystem->setTokenSecret($this->tokenEncryptionService->encryptToken($tokenSecret))
            ->setAccessToken($this->tokenEncryptionService->encryptToken($accessToken))
            ->setAvoidConnection($avoidConnection);

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($userTicketSystem);
        $objectManager->flush();

        // Return the plaintext the caller passed in, not the encrypted stored value,
        // so an immediate post-store request still uses a usable token.
        return [
            'oauth_token_secret' => $tokenSecret,
            'oauth_token' => $accessToken,
        ];
    }

    protected function getJiraBaseUrl(): string
    {
        return rtrim($this->ticketSystem->getUrl(), '/');
    }

    protected function getTokenSecret(): string
    {
        return $this->decryptStored($this->user->getTicketSystemAccessTokenSecret($this->ticketSystem) ?? '');
    }

    protected function getToken(): string
    {
        return $this->decryptStored($this->user->getTicketSystemAccessToken($this->ticketSystem) ?? '');
    }

    /**
     * Decrypts a stored OAuth token, transparently passing through legacy
     * unencrypted values written before encryption-at-rest was added.
     */
    protected function decryptStored(string $stored): string
    {
        if ('' === $stored) {
            return '';
        }

        try {
            return $this->tokenEncryptionService->decryptToken($stored);
        } catch (Exception) {
            return $stored;
        }
    }

    protected function getJiraApiUrl(): string
    {
        return $this->getJiraBaseUrl() . $this->jiraApiUrl;
    }

    protected function getOAuthRequestUrl(): string
    {
        return $this->getJiraBaseUrl() . $this->oAuthRequestUrl;
    }

    protected function getOAuthCallbackUrl(): string
    {
        return $this->oAuthCallbackUrl . '?tsid=' . $this->ticketSystem->getId();
    }

    protected function getOAuthAccessUrl(): string
    {
        return $this->getJiraBaseUrl() . $this->oAuthAccessUrl;
    }

    protected function getOAuthAuthUrl(#[SensitiveParameter] string $oAuthToken): string
    {
        return $this->getJiraBaseUrl() . $this->oAuthAuthUrl . '?oauth_token=' . $oAuthToken;
    }

    protected function getOAuthConsumerSecret(): string
    {
        return $this->ticketSystem->getOauthConsumerSecret() ?? '';
    }

    protected function getOAuthConsumerKey(): string
    {
        return $this->ticketSystem->getOauthConsumerKey() ?? '';
    }

    /**
     * Returns work log entry description for ticket system.
     */
    protected function getTicketSystemWorkLogComment(Entry $entry): string
    {
        $activity = $entry->getActivity() instanceof Activity
            ? $entry->getActivity()->getName()
            : 'no activity specified';

        $description = $entry->getDescription();
        if ('' === $description || '0' === $description) {
            $description = 'no description given';
        }

        return '#' . $entry->getId() . ': ' . $activity . ': ' . $description;
    }

    /**
     * Returns work log entry start date formatted for Jira API.
     * "2016-02-17T14:35:51.000+0100".
     */
    protected function getTicketSystemWorkLogStartDate(Entry $entry): string
    {
        $startDate = DateTime::createFromInterface($entry->getDay());
        $startDate->setTime(
            (int) $entry->getStart()->format('H'),
            (int) $entry->getStart()->format('i'),
        );

        return $startDate->format('Y-m-d\TH:i:s.000O');
    }

    /**
     * Checks if Jira interaction for user and ticket system should take place.
     */
    protected function checkUserTicketSystem(): bool
    {
        $repository = $this->managerRegistry->getRepository(UserTicketsystem::class);
        $result = $repository->findOneBy([
            'user' => $this->user,
            'ticketSystem' => $this->ticketSystem,
        ]);
        $userTicketSystem = $result instanceof UserTicketsystem ? $result : null;

        return $this->ticketSystem->getBookTime()
            && (!$userTicketSystem instanceof UserTicketsystem || !$userTicketSystem->getAvoidConnection());
    }

    /**
     * Throw an exception that causes the user to jump to Jira to authorize timetracker.
     *
     * @throws JiraApiUnauthorizedException
     *
     * @return never
     */
    protected function throwUnauthorizedRedirect(?Throwable $throwable = null)
    {
        try {
            $oauthAuthUrl = $this->fetchOAuthRequestToken();
        } catch (JiraApiException $jiraApiException) {
            throw new JiraApiException('Failed to fetch OAuth URL: ' . ($jiraApiException->getPrevious()?->getMessage() ?? $jiraApiException->getMessage()), 400, null, $jiraApiException);
        }

        $message = '401 - Unauthorized. Please authorize: ' . $oauthAuthUrl;
        throw new JiraApiUnauthorizedException($message, 401, $oauthAuthUrl, $throwable);
    }
}
