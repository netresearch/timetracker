<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

/**
 *
 * @todo create own entity for certificate
 * @todo create own entity for tokens
 */

namespace App\Helper;

use Doctrine\Persistence\ManagerRegistry;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;

use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class JiraOAuthApi
{
    /** @var string */
    protected $oAuthCallbackUrl;

    /** @var string */
    protected $jiraApiUrl = '/rest/api/latest/';

    /** @var string */
    protected $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';

    /** @var string */
    protected $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';

    /** @var string */
    protected $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';

    /** @var Client[] */
    protected $clients;


    /**
     * JiraOAuthApi constructor.
     */
    public function __construct(protected \App\Entity\User $user, protected \App\Entity\TicketSystem $ticketSystem, protected \Doctrine\Persistence\ManagerRegistry $managerRegistry, RouterInterface $router)
    {
        $this->oAuthCallbackUrl = $router->generate('jiraOAuthCallback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Returns a HTTP client preconfigured for OAuth request token retrieval.
     *
     * You need to retrieve an OAuth request token to initialize OAuth handshake.
     * With this OAuth request token you will redirect the user to OAuth service for authorizing the app.
     *
     * @throws JiraApiException
     */
    protected function getFetchRequestTokenClient()
    {
        return $this->getClient('new');
    }

    /**
     * Returns a HTTP client preconfigured for OAuth access token retrieval.
     *
     * After receiving the OAuth request token, the OAuth access token be retrieved.
     *
     * @param string $oAuthRequestToken OAuth request token
     * @return Client
     * @throws JiraApiException
     */
    protected function getFetchAccessTokenClient($oAuthRequestToken)
    {
        return $this->getClient('request', $oAuthRequestToken);
    }

    /**
     * Returns a HTTP client preconfigured for OAuth communication.
     *
     * @param string $tokenMode How to fetch the token:
     *                          - user: fetch user token from database
     *                          - new: obtain new user token
     *                          - request: fetch access token from given request token
     * @param string $oAuthToken Request token when supplied
     *
     * @return Client
     * @throws JiraApiException
     */
    protected function getClient($tokenMode = 'user', $oAuthToken = null)
    {
        if ($tokenMode === 'user') {
            $oAuthTokenSecret = $this->getTokenSecret();
            $oAuthToken = $this->getToken();
            if ($oAuthToken == '' && $oAuthTokenSecret == '') {
                $this->throwUnauthorizedRedirect(null);
            }
        } elseif ($tokenMode === 'new') {
            $oAuthToken = '';
            $oAuthTokenSecret = '';
        } elseif ($tokenMode === 'request') {
            $oAuthTokenSecret = '';
        } else {
            throw new \UnexpectedValueException('Invalid token mode: ' . $tokenMode);
        }

        $key = $oAuthToken . $oAuthTokenSecret;

        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $curlHandler = new CurlHandler();
        $handlerStack = HandlerStack::create($curlHandler);

        $oauth1 = new Oauth1([
            'consumer_key'     => $this->getOAuthConsumerKey(),
            'consumer_secret'  => $this->getOAuthConsumerSecret(),
            'token_secret'     => $oAuthTokenSecret,
            'token'            => $oAuthToken,
            'request_method'   => Oauth1::REQUEST_METHOD_QUERY,
            'signature_method' => Oauth1::SIGNATURE_METHOD_RSA,
            'private_key_file' => $this->getPrivateKeyFile(),
            'private_key_passphrase' => '',
        ]);
        $handlerStack->push($oauth1);

        $this->clients[$key] = new Client([
            'base_uri' => $this->getJiraApiUrl(),
            'handler'  => $handlerStack,
            'auth'     => 'oauth',
        ]);

        return $this->clients[$key];
    }

    /**
     * Returns path to private key file.
     *
     * @return string Path to certificate file
     * @throws JiraApiException
     */
    protected function getPrivateKeyFile()
    {
        $certificate = $this->getOAuthConsumerSecret();

        if (is_file($certificate)) {
            return $certificate;
        }

        $keyFileHeader = '-----BEGIN PRIVATE KEY-----';

        if (str_starts_with($certificate, $keyFileHeader)) {
            return $this->getTempKeyFile($certificate);
        }

        throw new JiraApiException(
            'Invalid certificate, fix your certificate information in ticket system settings for: "'
            . $this->ticketSystem->getName() . '"',
            1541160391
        );
    }

    /**
     * Returns temp key file name.
     *
     * @param string $certificate Private key
     * @return string
     */
    protected function getTempKeyFile($certificate)
    {
        $keyFile = $this->getTempFile();
        file_put_contents($keyFile, $certificate);

        return $keyFile;
    }

    /**
     * Returns temp file name.
     *
     * @return string
     */
    protected function getTempFile()
    {
        return tempnam(sys_get_temp_dir(), 'TTT');
    }

    /**
     * Fetches and Stores Jira access token
     *
     * @param string $oAuthRequestToken The OAuth request token retrieved after user granted access for this app
     * @param string $oAuthVerifier     The OAuth verifier retrieved after user granted access for this app
     * @throws JiraApiException
     */
    public function fetchOAuthAccessToken($oAuthRequestToken, $oAuthVerifier): void
    {
        try {
            if ($oAuthVerifier == 'denied') {
                $this->deleteTokens();
            } else {
                //$response = $this->oAuth->getAccessToken($this->getOAuthAccessUrl(), null, null, 'POST');

                $response = $this->getFetchAccessTokenClient($oAuthRequestToken)->post(
                    $this->getOAuthAccessUrl() . '?oauth_verifier=' . urlencode($oAuthVerifier)
                );
                $this->extractTokens($response);
            }
        } catch (\Throwable $throwable) {
            throw new JiraApiException($throwable->getMessage(), $throwable->getCode());
        }
    }

    /**
     * Delete stored tokens.
     *
     * @return void
     */
    protected function deleteTokens()
    {
        $this->storeToken('', '', true);
    }

    /**
     * Fetches request token
     *
     * @return string URL to Jira where User can allow / deny access
     * @throws JiraApiException
     */
    protected function fetchOAuthRequestToken(): string
    {
        try {
            $response = $this->getFetchRequestTokenClient()->post(
                $this->getOAuthRequestUrl() . '?oauth_callback=' . urlencode($this->getOAuthCallbackUrl())
            );

            $token = $this->extractTokens($response);

            return $this->getOAuthAuthUrl($token['oauth_token']);
        } catch (\Throwable $throwable) {
            throw new JiraApiException($throwable->getMessage(), $throwable->getCode(), null, $throwable);
        }
    }

    /**
     * @return string[]
     * @throws JiraApiException
     */
    protected function extractTokens(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        $token = [];
        parse_str($body, $token);

        if ($token === []) {
            throw new JiraApiException(
                "An unknown error occurred while requesting OAuth token.",
                1541147716
            );
        }

        return $this->storeToken($token['oauth_token_secret'], $token['oauth_token']);
    }

    /**
     *  Updates Jira work log entries to all user entries and the set ticket system
     */
    public function updateAllEntriesJiraWorkLogs(): void
    {
        $this->updateEntriesJiraWorkLogsLimited();
    }

    /**
     * Updates Jira work log entries to a set number of user entries and the set ticket system
     * (entries ordered by date, time desc)
     *
     * @param integer $entryLimit  (optional) max number of entries which should be updated (null: no limit)
     */
    public function updateEntriesJiraWorkLogsLimited($entryLimit = null): void
    {
        if (!$this->checkUserTicketSystem()) {
            return;
        }

        $em = $this->managerRegistry->getManager();
        $repo = $this->managerRegistry->getRepository(\App\Entity\Entry::class);
        $entries = $repo->findByUserAndTicketSystemToSync($this->user->getId(), $this->ticketSystem->getId(), $entryLimit);

        foreach ($entries as $entry) {
            try {
                $this->updateEntryJiraWorkLog($entry);
                $em->persist($entry);
            } catch (\Exception) {
            } finally {
                $em->flush();
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
        if (empty($sTicket)) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        if (!$this->doesTicketExist($sTicket)) {
            return;
        }

        if (!$entry->getDuration()) {
            // delete possible old work log entry
            $this->deleteEntryJiraWorkLog($entry);
            // without duration we do not add any work log entry as Jira complains
            return;
        }

        if ($entry->getWorklogId() && !$this->doesWorkLogExist($sTicket, $entry->getWorklogId())) {
            $entry->setWorklogId(null);
        }

        $arData = [
            'comment'          => $this->getTicketSystemWorkLogComment($entry),
            'started'          => $this->getTicketSystemWorkLogStartDate($entry),
            'timeSpentSeconds' => $entry->getDuration() * 60,
        ];

        if ($entry->getWorklogId()) {
            $workLog = $this->put(
                sprintf("issue/%s/worklog/%d", $sTicket, $entry->getWorklogId()),
                $arData
            );
        } else {
            $workLog = $this->post(sprintf("issue/%s/worklog", $sTicket), $arData);
        }

        $entry->setWorklogId($workLog->id);
        $entry->setSyncedToTicketsystem(true);
    }

    /**
     * Removes Jira workLog entry.
     *
     * @throws JiraApiException
     */
    public function deleteEntryJiraWorkLog(Entry $entry): void
    {
        $sTicket = $entry->getTicket();
        if (empty($sTicket)) {
            return;
        }

        if ((int) $entry->getWorklogId() <= 0) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        try {
            $this->delete(sprintf(
                "issue/%s/worklog/%d",
                $sTicket,
                $entry->getWorklogId()
            ));

            $entry->setWorklogId(null);
        } catch (JiraApiInvalidResourceException) {
        }
    }

    /**
     * @return string
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function createTicket(Entry $entry): mixed
    {
        return $this->post(
            "issue/",
            [
                'fields' => [
                    'project'     => [
                        'key' => $entry->getProject()->getInternalJiraProjectKey(),
                    ],
                    'summary'     => $entry->getTicket(),
                    'description' => $entry->getTicketSystemIssueLink(),
                    'issuetype'   => [
                        'name' => 'Task',
                    ],
                ],
            ]
        );
    }

    /**
     * @param string $jql
     * @param array  $fields
     * @param int    $limit
     * @return \stdClass
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    public function searchTicket($jql, $fields, $limit = 1): mixed
    {
        // we use POST to support very large queries
        return $this->post(
            "search/",
            [
                'jql'        => $jql,
                'fields'     => $fields,
                'maxResults' => $limit,
            ]
        );
    }

    /**
     * Checks existence of a ticket in Jira
     *
     * @param string $sTicket
     * @throws JiraApiException
     */
    public function doesTicketExist($sTicket): bool
    {
        return $this->doesResourceExist(sprintf("issue/%s", $sTicket));
    }

    /**
     * Get an array of ticket numbers that are subtickets of the given issue
     */
    public function getSubtickets(string $sTicket): array
    {
        if (!$this->doesTicketExist($sTicket)) {
            return [];
        }

        $ticket = $this->get('issue/' . $sTicket);

        $subtickets = [];
        foreach ($ticket->fields->subtasks as $subtask) {
            $subtickets[] = $subtask->key;
        }

        if (strtolower((string) $ticket->fields->issuetype->name) === 'epic') {
            $epicSubs = $this->searchTicket('"Epic Link" = ' . $sTicket, ['key', 'subtasks'], 100);
            foreach ($epicSubs->issues as $epicSubtask) {
                $subtickets[] = $epicSubtask->key;
                foreach ($epicSubtask->fields->subtasks as $subtask) {
                    $subtickets[] = $subtask->key;
                }
            }
        }

        return $subtickets;
    }

    /**
     * Checks existence of a work log entry in Jira
     *
     * @param string  $sTicket
     * @param integer $workLogId
     * @throws JiraApiException
     */
    protected function doesWorkLogExist($sTicket, $workLogId): bool
    {
        return $this->doesResourceExist(sprintf("issue/%s/worklog/%d", $sTicket, $workLogId));
    }

    /**
     * Checks existence of a Jira resource
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
     * @param  array  $data
     * @return string
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function post(string $url, $data = []): mixed
    {
        return $this->getResponse('POST', $url, $data);
    }

    /**
     * Execute PUT request and return response as simple object.
     *
     * @param  array  $data
     * @return string
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function put(string $url, $data = []): mixed
    {
        return $this->getResponse('PUT', $url, $data);
    }

    /**
     * @return string
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function delete(string $url): mixed
    {
        return $this->getResponse('DELETE', $url);
    }

    /**
     * Get Response of Jira-API request
     *
     * @param array $data
     * @throws JiraApiException
     * @throws JiraApiInvalidResourceException
     */
    protected function getResponse(string $method, string $url, $data = []): mixed
    {
        $additionalParameter = [];
        if (!empty($data)) {
            $additionalParameter = [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body'    => json_encode($data),
            ];
        }

        try {
            $response = $this->getClient()->request($method, $url, $additionalParameter);
        } catch (GuzzleException $guzzleException) {
            if ($guzzleException->getCode() == 401) {
                $this->throwUnauthorizedRedirect($guzzleException);
            } elseif ($guzzleException->getCode() === 404) {
                $message = '404 - Resource is not available: (' . $url . ')';
                throw new JiraApiInvalidResourceException($message, 404, $guzzleException);
            } else {
                throw new JiraApiException(
                    'Unknown Guzzle exception: ' . $guzzleException->getMessage(),
                    $guzzleException->getCode(),
                    null,
                    $guzzleException
                );
            }
        }

        return json_decode($response->getBody());
    }

    /**
     * Stores access token and token secret to Database
     *
     * @param string $tokenSecret
     * @param string $accessToken
     * @param bool $avoidConnection
     * @return string[]
     */
    protected function storeToken($tokenSecret, $accessToken = 'token_request_unfinished', $avoidConnection = false): array
    {
        /** @var UserTicketSystem $userTicketSystem */
        $userTicketSystem = $this->managerRegistry->getRepository(\App\Entity\UserTicketsystem::class)
            ->findOneBy([
                'user' => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ]);

        if (!$userTicketSystem) {
            $userTicketSystem = new UserTicketsystem();
            $userTicketSystem->setUser($this->user)
                ->setTicketSystem($this->ticketSystem);
        }

        $userTicketSystem->setTokenSecret($tokenSecret)
            ->setAccessToken($accessToken)
            ->setAvoidConnection($avoidConnection);

        $em = $this->managerRegistry->getManager();
        $em->persist($userTicketSystem);
        $em->flush();

        return [
            'oauth_token_secret' => $userTicketSystem->getTokenSecret(),
            'oauth_token'        => $userTicketSystem->getAccessToken(),
        ];
    }

    protected function getJiraBaseUrl(): string
    {
        return rtrim($this->ticketSystem->getUrl(), "/");
    }

    /**
     * @return string
     */
    protected function getTokenSecret()
    {
        return $this->user->getTicketSystemAccessTokenSecret($this->ticketSystem);
    }

    /**
     * @return string
     */
    protected function getToken()
    {
        return $this->user->getTicketSystemAccessToken($this->ticketSystem);
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

    protected function generateNonce(): string
    {
        return md5(microtime(true) . uniqid('', true));
    }

    /**
     * @return string
     */
    protected function getOAuthConsumerSecret()
    {
        return $this->ticketSystem->getOauthConsumerSecret();
    }

    /**
     * @return string
     */
    protected function getOAuthConsumerKey()
    {
        return $this->ticketSystem->getOauthConsumerKey();
    }

    /**
     * Returns work log entry description for ticket system.
     */
    protected function getTicketSystemWorkLogComment(Entry $entry): string
    {
        $activity = $entry->getActivity()
            ? $entry->getActivity()->getName()
            : 'no activity specified';

        $description = $entry->getDescription();
        if (empty($description)) {
            $description = 'no description given';
        }

        return '#' . $entry->getId() . ': ' . $activity . ': ' . $description;
    }

    /**
     * Returns work log entry start date formatted for Jira API.
     * //"2016-02-17T14:35:51.000+0100"
     *
     * @return string "2016-02-17T14:35:51.000+0100"
     */
    protected function getTicketSystemWorkLogStartDate(Entry $entry): string
    {
        $startDate = $entry->getDay() ?: new \DateTime();
        if ($entry->getStart()) {
            $startDate->setTime(
                $entry->getStart()->format('H'),
                $entry->getStart()->format('i')
            );
        }

        return $startDate->format('Y-m-d\TH:i:s.000O');
    }

    /**
     * Checks if Jira interaction for user and ticket system should take place
     */
    protected function checkUserTicketSystem(): bool
    {
        /** @var UserTicketsystem $userTicketSystem */
        $userTicketSystem = $this->managerRegistry
            ->getRepository(\App\Entity\UserTicketsystem::class)
            ->findOneBy([
                'user' => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ]);

        return ((bool) $this->ticketSystem->getBookTime()
            && (!$userTicketSystem || !$userTicketSystem->getAvoidConnection())
        );
    }

    /**
     * Throw an exception that causes the user to jump to Jira to authorize timetracker
     *
     * @throws JiraApiUnauthorizedException
     */
    protected function throwUnauthorizedRedirect(\Exception $exception = null)
    {
        try {
            $oauthAuthUrl = $this->fetchOAuthRequestToken();
        } catch (JiraApiException $jiraApiException) {
            throw new JiraApiException(
                'Failed to fetch OAuth URL: ' . $jiraApiException->getPrevious()->getMessage(),
                400,
                null,
                $jiraApiException
            );
        }

        $message = '401 - Unauthorized. Please authorize: ' . $oauthAuthUrl;
        throw new JiraApiUnauthorizedException($message, 401, $oauthAuthUrl, $exception);
    }
}
