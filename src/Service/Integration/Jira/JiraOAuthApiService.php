<?php

declare(strict_types=1);

namespace App\Service\Integration\Jira;

use App\Entity\Entry;
use App\Entity\UserTicketsystem;
use App\Exception\Integration\Jira\JiraApiException;
use App\Exception\Integration\Jira\JiraApiInvalidResourceException;
use App\Exception\Integration\Jira\JiraApiUnauthorizedException;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Throwable;
use UnexpectedValueException;

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
        protected \App\Entity\User $user,
        protected \App\Entity\TicketSystem $ticketSystem,
        protected ManagerRegistry $managerRegistry,
        RouterInterface $router,
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
    protected function getFetchAccessTokenClient(string $oAuthRequestToken): Client
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
    protected function getClient(string $tokenMode = 'user', ?string $oAuthToken = null): Client
    {
        if ('user' === $tokenMode) {
            $oAuthTokenSecret = $this->getTokenSecret();
            $oAuthToken = $this->getToken();
            if ('' === $oAuthToken && '' === $oAuthTokenSecret) {
                $this->throwUnauthorizedRedirect(null);
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
    public function fetchOAuthAccessToken(string $oAuthRequestToken, string $oAuthVerifier): void
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

            $token = $this->extractTokens($response);

            return $this->getOAuthAuthUrl($token['oauth_token']);
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

        $token = [];
        parse_str($body, $token);

        if ([] === $token) {
            throw new JiraApiException('An unknown error occurred while requesting OAuth token.', 1541147716);
        }

        $secret = is_string($token['oauth_token_secret'] ?? null) ? $token['oauth_token_secret'] : '';
        $access = is_string($token['oauth_token'] ?? null) ? $token['oauth_token'] : '';

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
        /** @var \App\Repository\EntryRepository $objectRepository */
        $objectRepository = $this->managerRegistry->getRepository(Entry::class);
        $entries = $objectRepository->findByUserAndTicketSystemToSync((int) $this->user->getId(), (int) $this->ticketSystem->getId(), $entryLimit ?? 50);

        foreach ($entries as $entry) {
            try {
                $this->updateEntryJiraWorkLog($entry);
                $objectManager->persist($entry);
            } catch (Exception) {
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

        if ($entry->getWorklogId()) {
            $workLogId = (int) $entry->getWorklogId();
            $workLog = $this->put(
                sprintf('issue/%s/worklog/%d', $sTicket, $workLogId),
                $arData,
            );
        } else {
            $workLog = $this->post(sprintf('issue/%s/worklog', $sTicket), $arData);
        }

        if (!isset($workLog->id)) {
            throw new JiraApiException('Unexpected response from Jira when updating worklog', 500);
        }

        $entry->setWorklogId((int) $workLog->id);
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

        if ((int) $entry->getWorklogId() <= 0) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        try {
            $workLogId = $entry->getWorklogId() ?? 0;
            $this->delete(sprintf(
                'issue/%s/worklog/%d',
                $sTicket,
                $workLogId,
            ));

            $entry->setWorklogId(null);
        } catch (JiraApiInvalidResourceException) {
        }
    }

    /**
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function createTicket(Entry $entry): mixed
    {
        $project = $entry->getProject();
        if (!$project instanceof \App\Entity\Project) {
            throw new JiraApiException('Entry has no project', 400);
        }

        return $this->post(
            'issue/',
            [
                'fields' => [
                    'project' => [
                        'key' => (string) $project->getInternalJiraProjectKey(),
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
     * @psalm-return list<mixed>
     */
    public function getSubtickets(string $sTicket): array
    {
        if (!$this->doesTicketExist($sTicket)) {
            return [];
        }

        $ticket = $this->get('issue/' . $sTicket);

        // Ensure ticket response is an object with expected structure
        if (!is_object($ticket) || !isset($ticket->fields)) {
            return [];
        }

        $subtickets = [];

        // Check if subtasks exist and is iterable
        if (isset($ticket->fields->subtasks) && is_iterable($ticket->fields->subtasks)) {
            foreach ($ticket->fields->subtasks as $subtask) {
                if (is_object($subtask) && isset($subtask->key)) {
                    $subtickets[] = $subtask->key;
                }
            }
        }

        // Check for epic type tickets
        if (isset($ticket->fields->issuetype)
            && is_object($ticket->fields->issuetype)
            && isset($ticket->fields->issuetype->name)
            && 'epic' === strtolower((string) $ticket->fields->issuetype->name)) {
            $epicSubs = $this->searchTicket('"Epic Link" = ' . $sTicket, ['key', 'subtasks'], 100);

            // Ensure epic search results have expected structure
            if (is_object($epicSubs) && isset($epicSubs->issues) && is_iterable($epicSubs->issues)) {
                foreach ($epicSubs->issues as $epicSubtask) {
                    if (is_object($epicSubtask) && isset($epicSubtask->key)) {
                        $subtickets[] = $epicSubtask->key;

                        // Check for nested subtasks
                        if (isset($epicSubtask->fields)
                            && is_object($epicSubtask->fields)
                            && isset($epicSubtask->fields->subtasks)
                            && is_iterable($epicSubtask->fields->subtasks)) {
                            foreach ($epicSubtask->fields->subtasks as $subtask) {
                                if (is_object($subtask) && isset($subtask->key)) {
                                    $subtickets[] = $subtask->key;
                                }
                            }
                        }
                    }
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
        return $this->doesResourceExist(sprintf('issue/%s/worklog/%d', $sTicket, $workLogId));
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
                $this->throwUnauthorizedRedirect(null);
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
     * Stores access token and token secret to Database.
     *
     * @return array{oauth_token_secret:string,oauth_token:string}
     */
    protected function storeToken(string $tokenSecret, string $accessToken = 'token_request_unfinished', bool $avoidConnection = false): array
    {
        /** @var UserTicketsystem $userTicketSystem */
        $userTicketSystem = $this->managerRegistry->getRepository(UserTicketsystem::class)
            ->findOneBy([
                'user' => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ])
        ;

        if (!$userTicketSystem) {
            $userTicketSystem = new UserTicketsystem();
            $userTicketSystem->setUser($this->user)
                ->setTicketSystem($this->ticketSystem)
            ;
        }

        $userTicketSystem->setTokenSecret($tokenSecret)
            ->setAccessToken($accessToken)
            ->setAvoidConnection($avoidConnection)
        ;

        $objectManager = $this->managerRegistry->getManager();
        $objectManager->persist($userTicketSystem);
        $objectManager->flush();

        return [
            'oauth_token_secret' => $userTicketSystem->getTokenSecret(),
            'oauth_token' => $userTicketSystem->getAccessToken(),
        ];
    }

    protected function getJiraBaseUrl(): string
    {
        return rtrim($this->ticketSystem->getUrl(), '/');
    }

    protected function getTokenSecret(): string
    {
        return $this->user->getTicketSystemAccessTokenSecret($this->ticketSystem) ?? '';
    }

    protected function getToken(): string
    {
        return $this->user->getTicketSystemAccessToken($this->ticketSystem) ?? '';
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

    protected function getOAuthAuthUrl(string $oAuthToken): string
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
        $activity = $entry->getActivity() instanceof \App\Entity\Activity
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
        /** @var UserTicketsystem $userTicketSystem */
        $userTicketSystem = $this->managerRegistry
            ->getRepository(UserTicketsystem::class)
            ->findOneBy([
                'user' => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ])
        ;

        return $this->ticketSystem->getBookTime()
            && (!$userTicketSystem || !$userTicketSystem->getAvoidConnection());
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
