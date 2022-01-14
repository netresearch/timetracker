<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

/**
 * @todo create own entity for certificate
 * @todo create own entity for tokens
 */

namespace App\Helper;

use Doctrine\Persistence\ManagerRegistry;
use Throwable;
use Exception;
use stdClass;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Subscriber\Oauth\Oauth1;
use App\Entity\Entry;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Entity\UserTicketsystem;
use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class JiraOAuthApi
{
    /** @var string */
    protected string $oAuthCallbackUrl;

    /** @var string */
    protected string $jiraApiUrl = '/rest/api/latest/';
    /** @var string */
    protected string $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';
    /** @var string */
    protected string $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';
    /** @var string */
    protected string $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';
    /** @var Client[] */
    protected array $clients;

    /**
     * JiraOAuthApi constructor.
     */
    public function __construct(
        protected User $user,
        protected TicketSystem $ticketSystem,
        protected ManagerRegistry $doctrine,
        protected EntryRepository $entryRepo,
        protected EntityManagerInterface $em,
        Router $router
    ) {
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
        return $this->getClient('', '');
    }

    /**
     * Returns a HTTP client preconfigured for OAuth access token retrieval.
     *
     * After receiving the OAuth request token, the OAuth access token be retrieved.
     *
     * @param string $oAuthRequestToken OAuth request token
     *
     * @throws JiraApiException
     *
     * @return Client
     */
    protected function getFetchAccessTokenClient(string $oAuthRequestToken): Client
    {
        return $this->getClient($oAuthRequestToken);
    }

    /**
     * Returns a HTTP client preconfigured for OAuth communication.
     *
     * @param string $oAuthToken
     * @param string $oAuthTokenSecret
     *
     * @throws JiraApiException
     *
     * @return Client
     */
    protected function getClient(string $oAuthToken = null, string $oAuthTokenSecret = null): Client
    {
        if (null === $oAuthTokenSecret) {
            $oAuthTokenSecret = $this->getTokenSecret();
        }

        if (null === $oAuthToken) {
            $oAuthToken = $this->getToken();
        }

        $key = (string) $oAuthToken.(string) $oAuthTokenSecret;

        if (isset($this->clients[$key])) {
            return $this->clients[$key];
        }

        $handler = new CurlHandler();
        $stack   = HandlerStack::create($handler);

        $middleware = new Oauth1([
            'consumer_key'           => $this->getOAuthConsumerKey(),
            'consumer_secret'        => $this->getOAuthConsumerSecret(),
            'token_secret'           => $oAuthTokenSecret,
            'token'                  => $oAuthToken,
            'request_method'         => Oauth1::REQUEST_METHOD_QUERY,
            'signature_method'       => Oauth1::SIGNATURE_METHOD_RSA,
            'private_key_file'       => $this->getPrivateKeyFile(),
            'private_key_passphrase' => '',
        ]);
        $stack->push($middleware);

        $this->clients[$key] = new Client([
            'base_uri' => $this->getJiraApiUrl(),
            'handler'  => $stack,
            'auth'     => 'oauth',
        ]);

        return $this->clients[$key];
    }

    /**
     * Returns path to private key file.
     *
     * @throws JiraApiException
     *
     * @return string Path to certificate file
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

        throw new JiraApiException('Invalid certificate, fix your certificate information in ticket system settings.', 1_541_160_391);
    }

    /**
     * Returns temp key file name.
     *
     * @param string $certificate Private key
     *
     * @return string
     */
    protected function getTempKeyFile(string $certificate): string
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
    protected function getTempFile(): string
    {
        return tempnam(sys_get_temp_dir(), 'TTT');
    }

    /**
     * Fetches and Stores Jira access token.
     *
     * @param string $oAuthRequestToken The OAuth request token retrieved after user granted access for this app
     * @param string $oAuthVerifier     The OAuth verifier retrieved after user granted access for this app
     *
     * @throws JiraApiException
     */
    public function fetchOAuthAccessToken(string $oAuthRequestToken, string $oAuthVerifier): void
    {
        try {
            if ('denied' === $oAuthVerifier) {
                $this->deleteTokens();
            } else {
                //$response = $this->oAuth->getAccessToken($this->getOAuthAccessUrl(), null, null, 'POST');

                $response = $this->getFetchAccessTokenClient($oAuthRequestToken)->post(
                    $this->getOAuthAccessUrl().'?oauth_verifier='.urlencode($oAuthVerifier)
                );
                $this->extractTokens($response);
            }
        } catch (Throwable $e) {
            throw new JiraApiException($e->getMessage(), $e->getCode());
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
     *
     * @return string URL to Jira where User can allow / deny access
     */
    protected function fetchOAuthRequestToken(): string
    {
        try {
            $response = $this->getFetchRequestTokenClient()->post(
                $this->getOAuthRequestUrl().'?oauth_callback='.urlencode($this->getOAuthCallbackUrl())
            );

            $token = $this->extractTokens($response);

            return $this->getOAuthAuthUrl($token['oauth_token']);
        } catch (Throwable $e) {
            throw new JiraApiException($e->getMessage(), $e->getCode(), null);
        }
    }

    /**
     * @throws JiraApiException
     *
     * @return string[]
     */
    protected function extractTokens(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();

        $token = [];
        parse_str($body, $token);

        if (empty($token)) {
            throw new JiraApiException('An unknown error occurred while requesting OAuth token.', 1_541_147_716);
        }

        return $this->storeToken($token['oauth_token_secret'], $token['oauth_token']);
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
     *
     * @param int $entryLimit (optional) max number of entries which should be updated (null: no limit)
     */
    public function updateEntriesJiraWorkLogsLimited(int $entryLimit = null): void
    {
        if (!$this->checkUserTicketSystem()) {
            return;
        }

        $entries = $this->entryRepo->findByUserAndTicketSystemToSync($this->user->getId(), $this->ticketSystem->getId(), $entryLimit);

        foreach ($entries as $entry) {
            try {
                $this->updateEntryJiraWorkLog($entry);
                $this->em->persist($entry);
            } catch (Exception) {
            } finally {
                $this->em->flush();
            }
        }
    }

    /**
     * Create or update Jira work log entry.
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
                sprintf('issue/%s/worklog/%d', $sTicket, $entry->getWorklogId()),
                $arData
            );
        } else {
            $workLog = $this->post(sprintf('issue/%s/worklog', $sTicket), $arData);
        }

        $entry->setWorklogId($workLog->id);
        $entry->setSyncedToTicketsystem(true);
    }

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
                'issue/%s/worklog/%d',
                $sTicket,
                $entry->getWorklogId()
            ));

            $entry->setWorklogId(null);
        } catch (JiraApiInvalidResourceException) {
        }
    }

    public function createTicket(Entry $entry): string
    {
        return $this->post(
            'issue/',
            [
                'fields' => [
                    'project' => [
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
     * @return stdClass
     */
    public function searchTicket(string $jql, string|array $fields, int $limit = 1)
    {
        //we use POST to support very large queries
        return $this->post(
            'search/',
            [
                'jql'        => $jql,
                'fields'     => $fields,
                'maxResults' => $limit,
            ]
        );
    }

    /**
     * Checks existence of a ticket in Jira.
     */
    public function doesTicketExist(string $sTicket): bool
    {
        return $this->doesResourceExist(sprintf('issue/%s', $sTicket));
    }

    /**
     * Checks existence of a work log entry in Jira.
     */
    protected function doesWorkLogExist(string $sTicket, int $workLogId): bool
    {
        return $this->doesResourceExist(sprintf('issue/%s/worklog/%d', $sTicket, $workLogId));
    }

    /**
     * Checks existence of a Jira resource.
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
     */
    protected function get(string $url): mixed
    {
        return $this->getResponse('GET', $url);
    }

    /**
     * Execute POST request and return response as simple object.
     */
    protected function post(string $url, array $data = []): string
    {
        return $this->getResponse('POST', $url, $data);
    }

    /**
     * Execute PUT request and return response as simple object.
     */
    protected function put(string $url, array $data = []): string
    {
        return $this->getResponse('PUT', $url, $data);
    }

    protected function delete(string $url): string
    {
        return $this->getResponse('DELETE', $url);
    }

    /**
     * Get Response of Jira-API request.
     */
    protected function getResponse(string $method, string $url, array $data = []): mixed
    {
        $additionalParameter = [];
        if (!empty($data)) {
            $additionalParameter = [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($data, \JSON_THROW_ON_ERROR),
            ];
        }

        try {
            $response = $this->getClient()->request($method, $url, $additionalParameter);
        } catch (GuzzleException $e) {
            if (401 === $e->getCode()) {
                $oauthAuthUrl = $this->fetchOAuthRequestToken();
                $message      = 'Jira: 401 - Unauthorized. Please authorize: '.$oauthAuthUrl;
                throw new JiraApiException($message, $e->getCode(), $oauthAuthUrl);
            }
            if (404 === $e->getCode()) {
                $message = 'Jira: 404 - Resource is not available: ('.$url.')';
                throw new JiraApiInvalidResourceException($message);
            }
            throw new JiraApiException('Unknown Guzzle exception: '.$e->getMessage(), $e->getCode());
        }

        return json_decode($response->getBody(), null, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Stores access token and token secret to Database.
     *
     * @return string[]
     */
    protected function storeToken(string $tokenSecret, string $accessToken = 'token_request_unfinished', bool $avoidConnection = false): array
    {
        /** @var UserTicketSystem $userTicketSystem */
        $userTicketSystem = $this->doctrine->getRepository('App:UserTicketsystem')
            ->findOneBy([
                'user'         => $this->user,
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

        $this->em->persist($userTicketSystem);
        $this->em->flush();

        return [
            'oauth_token_secret' => $userTicketSystem->getTokenSecret(),
            'oauth_token'        => $userTicketSystem->getAccessToken(),
        ];
    }

    protected function getJiraBaseUrl(): string
    {
        return rtrim($this->ticketSystem->getUrl(), '/');
    }

    protected function getTokenSecret(): string
    {
        return $this->user->getTicketSystemAccessTokenSecret($this->ticketSystem);
    }

    protected function getToken(): string
    {
        return $this->user->getTicketSystemAccessToken($this->ticketSystem);
    }

    protected function getJiraApiUrl(): string
    {
        return $this->getJiraBaseUrl().$this->jiraApiUrl;
    }

    protected function getOAuthRequestUrl(): string
    {
        return $this->getJiraBaseUrl().$this->oAuthRequestUrl;
    }

    protected function getOAuthCallbackUrl(): string
    {
        return $this->oAuthCallbackUrl.'?tsid='.$this->ticketSystem->getId();
    }

    protected function getOAuthAccessUrl(): string
    {
        return $this->getJiraBaseUrl().$this->oAuthAccessUrl;
    }

    protected function getOAuthAuthUrl(string $oAuthToken): string
    {
        return $this->getJiraBaseUrl().$this->oAuthAuthUrl.'?oauth_token='.$oAuthToken;
    }

    protected function generateNonce(): string
    {
        return md5(microtime(true).uniqid('', true));
    }

    protected function getOAuthConsumerSecret(): string
    {
        return $this->ticketSystem->getOauthConsumerSecret();
    }

    protected function getOAuthConsumerKey(): string
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

        return '#'.$entry->getId().': '.$activity.': '.$description;
    }

    /**
     * Returns work log entry start date formatted for Jira API.
     * //"2016-02-17T14:35:51.000+0100".
     *
     * @return string "2016-02-17T14:35:51.000+0100"
     */
    protected function getTicketSystemWorkLogStartDate(Entry $entry): string
    {
        $startDate = $entry->getDay() ?: new DateTime();
        if ($entry->getStart()) {
            $startDate->setTime(
                $entry->getStart()->format('H'),
                $entry->getStart()->format('i')
            );
        }

        return $startDate->format('Y-m-d\TH:i:s.000O');
    }

    /**
     * Checks if Jira interaction for user and ticket system should take place.
     */
    protected function checkUserTicketSystem(): bool
    {
        /** @var UserTicketsystem $userTicketSystem */
        $userTicketSystem = $this->doctrine
            ->getRepository('App:UserTicketsystem')
            ->findOneBy([
                'user'         => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ])
        ;

        return (bool) $this->ticketSystem->getBookTime()
            && (!$userTicketSystem || !$userTicketSystem->getAvoidConnection())
        ;
    }
}
