<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use Netresearch\TimeTrackerBundle\Entity\Entry;
use Netresearch\TimeTrackerBundle\Entity\EntryRepository;
use OAuth;

use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\UserTicketsystem;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class JiraOAuthApi
{
    /** @var User */
    protected $user;
    /** @var TicketSystem */
    protected $ticketSystem;
    /** @var Registry */
    protected $doctrine;
    /** @var OAuth */
    protected $oAuth;
    /** @var string */
    protected $oAuthCallbackUrl;

    /** @var string */
    protected $jiraApiUrl = '/rest/api/2';
    /** @var string */
    protected $oAuthRequestUrl = '/plugins/servlet/oauth/request-token';
    /** @var string */
    protected $oAuthAccessUrl = '/plugins/servlet/oauth/access-token';
    /** @var string */
    protected $oAuthAuthUrl = '/plugins/servlet/oauth/authorize';


    /**
     * JiraOAuthApi constructor.
     *
     * @param User $user
     * @param TicketSystem $ticketSystem
     * @param Registry $doctrine
     * @param Router $router
     * @throws JiraApiException
     */
    public function __construct(User $user, TicketSystem $ticketSystem, Registry $doctrine, Router $router)
    {
        $this->user = $user;
        $this->ticketSystem = $ticketSystem;
        $this->doctrine = $doctrine;
        $this->oAuthCallbackUrl = $router->generate('jiraOAuthCallback', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $this->oAuth = new OAuth($this->getOAuthConsumerKey(), $this->getOAuthConsumerSecret(), OAUTH_SIG_METHOD_RSASHA1, OAUTH_AUTH_TYPE_URI);
            $this->oAuth->setRSACertificate($this->getOAuthConsumerSecret());
            $this->oAuth->setRequestEngine(OAUTH_REQENGINE_CURL);
            $this->oAuth->setAuthType(OAUTH_AUTH_TYPE_URI);
        } catch (\Exception $e) {
            throw new JiraApiException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Fetches and Stores JIRA access token
     *
     * @param $oAuthToken
     * @param $oAuthVerifier
     * @throws JiraApiException
     */
    public function fetchOAuthAccessToken($oAuthToken, $oAuthVerifier)
    {
        try {
            if ($oAuthVerifier == 'denied') {
                $this->storeToken('', '', true);
            } else {
                $this->oAuth->setToken($oAuthToken, $this->getTokensecret());
                $this->oAuth->setTimestamp(time());
                $this->oAuth->setNonce($this->generateNonce());
                $response = $this->oAuth->getAccessToken($this->getOAuthAccessUrl(), null, null, 'POST');
                $this->storeToken($response['oauth_token_secret'], $response['oauth_token']);
            }
        } catch (\Exception $e) {
            throw new JiraApiException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * Fetches request token
     *
     * @return string URL to JIRA where User can allow / denie access
     * @throws JiraApiException
     */
    protected function fetchOAuthRequestToken()
    {
        try {
            $this->oAuth->setNonce($this->generateNonce());
            $this->oAuth->setTimestamp(time());
            $response = $this->oAuth->getRequestToken($this->getOAuthRequestUrl(), $this->getOAuthCallbackUrl(), 'POST');
            $this->storeToken($response['oauth_token_secret']);
            return $this->getOAuthAuthUrl($response['oauth_token']);
        } catch (\Exception $e) {
            throw new JiraApiException($e->getMessage(), $e->getCode(), null);
        }
    }

    /**
     *  Updates JIRA work logs to all user entries and the set ticket system
     */
    public function updateAllEntriesJiraWorkLogs()
    {
        if (!$this->checkUserTicketSystem()) {
            return;
        }

        $em = $this->doctrine->getManager();
        /** @var EntryRepository $repo */
        $repo = $this->doctrine->getRepository('NetresearchTimeTrackerBundle:Entry');
        $entries = $repo->findByUserAndTicketSystem($this->user->getId(), $this->ticketSystem->getId());

        /** @var Entry $entry */
        foreach ($entries as $entry) {
            try {
                $this->updateEntryJiraWorkLog($entry);
                $em->persist($entry);
                $em->flush();
            } catch (\Exception $e) {}
        }
    }

    /**
     * Create or Update JIRA workLog entry.
     *
     * @param Entry $entry
     */
    public function updateEntryJiraWorkLog(Entry $entry)
    {
        $sTicket = $entry->getTicket();
        if (empty($sTicket)) {
            return;
        }

        if (!$this->checkUserTicketSystem()) {
            return;
        }

        if (!$this->doesTicketExsist($sTicket)) {
            return;
        }

        if (!$entry->getDuration()) {
            // delete possible old worklog
            $this->deleteEntryJiraWorkLog($entry);
            // without duration we do not add any worklog as JIRA complains
            return;
        }

        if ($entry->getWorklogId() && !$this->doesWorklogExist($sTicket, $entry->getWorklogId())) {
            $entry->setWorklogId(null);
        }

        $arData = [
            'comment' => $this->getTicketSystemWorkLogComment($entry),
            'started' => $this->getTicketSystemWorkLogStartDate($entry),
            'timeSpentSeconds' => $entry->getDuration() * 60,
        ];

        if ($entry->getWorklogId()) {
            $workLog = $this->put(
                sprintf("/issue/%s/worklog/%d", $sTicket, $entry->getWorklogId()),
                $arData
            );
        } else {
            $workLog = $this->post(sprintf("/issue/%s/worklog", $sTicket), $arData);
        }

        $entry->setWorklogId($workLog->id);
    }

    /**
     * Removes JIRA workLog entry.
     *
     * @param Entry $entry
     */
    public function deleteEntryJiraWorkLog(Entry $entry)
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
                "/issue/%s/worklog/%d",
                $sTicket,
                $entry->getWorklogId()
            ));

            $entry->setWorklogId(NULL);
        } catch (JiraApiInvalidResourceException $e) {}
    }

    /**
     * Checks existence of a ticketname in JIRA
     *
     * @param string $sTicket
     * @return bool
     */
    public function doesTicketExsist($sTicket)
    {
        return $this->doesResourceExsist(sprintf("/issue/%s", $sTicket));
    }

    /**
     * Checks existence of a worklog in JIRA
     *
     * @param string    $sTicket
     * @param integer   $worklogId
     * @return bool
     */
    public function doesWorklogExist($sTicket, $worklogId)
    {
        return $this->doesResourceExsist(sprintf("/issue/%s/worklog/%d", $sTicket, $worklogId));
    }

    /**
     * Checks existence of a JIRA resource
     *
     * @param string $url
     * @return bool
     */
    public function doesResourceExsist($url)
    {
        try {
            $this->get($url);
        } catch (JiraApiInvalidResourceException $e) {
            return false;
        }
        return true;
    }

    /**
     * Execute GET request and return response as simple object.
     *
     * @param string $url
     * @return \stdClass
     */
    public function get($url)
    {
        $response = $this->getResponse(OAUTH_HTTP_METHOD_GET, $url);
        return json_decode($response);
    }

    /**
     * Execute POST request and return response as simple object.
     *
     * @param  string $url
     * @param  array  $data
     * @return \stdClass
     */
    public function post($url, $data = [])
    {
        $response = $this->getResponse(OAUTH_HTTP_METHOD_POST, $url, $data);
        return json_decode($response);
    }

    /**
     * Execute PUT request and return response as simple object.
     *
     * @param  string $url
     * @param  array  $data
     * @return \stdClass
     */
    public function put($url, $data = [])
    {
        $response = $this->getResponse(OAUTH_HTTP_METHOD_PUT, $url, $data);
        return json_decode($response);
    }

    /**
     * @param string $url
     * @return \stdClass
     */
    public function delete($url)
    {
        $response = $this->getResponse(OAUTH_HTTP_METHOD_DELETE, $url);
        return json_decode($response);
    }

    /**
     * Get Response of JIRA-API request
     *
     * @param string $method
     * @param string $url
     * @param array $data
     * @return string
     * @throws JiraApiInvalidResourceException
     * @throws JiraApiException
     */
    protected function getResponse($method, $url, $data = [])
    {
        $response = null;

        try {
            $this->oAuth->setToken($this->getToken(), $this->getTokensecret());
            $this->oAuth->setNonce($this->generateNonce());
            $this->oAuth->setTimestamp(time());

            $additionalParameter = [];
            if (!empty($data)) {
                $additionalParameter = ['Content-Type' => 'application/json'];
                $data = json_encode($data);
            }

            $this->oAuth->fetch($this->getJiraApiUrl() . $url, $data, $method, $additionalParameter);
            $response = $this->oAuth->getLastResponse();
        } catch (\Exception $e) {
            $oauthAuthUrl = null;

            if ($e->getCode() === 404) {
                $message = 'Jira: Resource is not available (' . $url . ')';
                throw new JiraApiInvalidResourceException($message);
            } else if ($e->getCode() === 401) {
                $oauthAuthUrl = $this->fetchOAuthRequestToken();
                $message = 'Invalid Ticketsystem Token (Jira) you\'re going to be forwarded';
                throw new JiraApiException($message, $e->getCode(), $oauthAuthUrl);
            } else {
                throw new JiraApiException($e->getMessage(), $e->getCode());
            }
        }

        return $response;
    }

    /**
     * Stores access token and token secret to Database
     *
     * @param string $tokenSecret
     * @param string $accessToken
     * @param bool $avoidConnection
     */
    protected function storeToken($tokenSecret, $accessToken = 'token_request_unfinished', $avoidConnection = false)
    {
        /** @var UserTicketsystem $userTicketsystem */
        $userTicketsystem = $this->doctrine->getRepository('NetresearchTimeTrackerBundle:UserTicketsystem')->findOneBy([
            'user' => $this->user,
            'ticketSystem' => $this->ticketSystem,
        ]);

        if (!$userTicketsystem) {
            $userTicketsystem = new UserTicketsystem();
            $userTicketsystem->setUser($this->user)
                ->setTicketSystem($this->ticketSystem);
        }

        $userTicketsystem->setTokenSecret($tokenSecret)
            ->setAccessToken($accessToken)
            ->setAvoidConnection($avoidConnection);

        $em = $this->doctrine->getManager();
        $em->persist($userTicketsystem);
        $em->flush();
    }

    /**
     * @return string
     */
    protected function getJiraBaseUrl()
    {
        return rtrim($this->ticketSystem->getUrl(), "/");
    }

    /**
     * @return string
     */
    protected function getTokensecret()
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

    /**
     * @return string
     */
    protected function getJiraApiUrl()
    {
        return $this->getJiraBaseUrl() . $this->jiraApiUrl;
    }

    /**
     * @return string
     */
    protected function getOAuthRequestUrl()
    {
        return $this->getJiraBaseUrl() . $this->oAuthRequestUrl;
    }

    /**
     * @return string
     */
    protected function getOAuthCallbackUrl()
    {
        return $this->oAuthCallbackUrl . '?tsid=' . $this->ticketSystem->getId();
    }

    /**
     * @return string
     */
    protected function getOAuthAccessUrl()
    {
        return $this->getJiraBaseUrl() . $this->oAuthAccessUrl;
    }


    /**
     * @param String $oAuthToken
     * @return string
     */
    protected function getOAuthAuthUrl($oAuthToken)
    {
        return $this->getJiraBaseUrl() . $this->oAuthAuthUrl . '?oauth_token=' . $oAuthToken;
    }

    /**
     * @return string
     */
    protected function generateNonce()
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
     *
     * @param  Entry $entry
     * @return string
     */
    public function getTicketSystemWorkLogComment(Entry $entry)
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
     * Returns work log entry start date formatted for JIRA API.
     * //"2016-02-17T14:35:51.000+0100"
     *
     * @param  Entry $entry
     * @return string "2016-02-17T14:35:51.000+0100"
     */
    public function getTicketSystemWorkLogStartDate(Entry $entry)
    {
        $startDate = $entry->getDay() ? $entry->getDay() : new \DateTime();
        if ($entry->getStart()) {
            $startDate->setTime(
                $entry->getStart()->format('H'), $entry->getStart()->format('i')
            );
        }

        return $startDate->format('Y-m-d\TH:i:s.000O');
    }

    /**
     * Checks if JIRA interaction for User and Ticketsystem should take place
     *
     * @return boolean
     */
    protected function checkUserTicketSystem()
    {
        /** @var UserTicketsystem $userTicketsystem */
        $userTicketsystem = $this->doctrine
            ->getRepository('NetresearchTimeTrackerBundle:UserTicketsystem')
            ->findOneBy([
                'user' => $this->user,
                'ticketSystem' => $this->ticketSystem,
            ]
         );

        return ((bool) $this->ticketSystem->getBookTime()
            && (!$userTicketsystem || !$userTicketsystem->getAvoidConnection())
        );
    }
}
