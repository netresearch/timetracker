<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use OAuth;

use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\UserTicketsystem;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class JiraUserApi
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
     * Execute GET request and return response as simple object.
     *
     * @param string $url
     * @return \stdClass
     * @throws JiraApiException
     * @throws \Exception
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
     * @throws JiraApiException
     * @throws \Exception
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
     * @throws JiraApiException
     * @throws \Exception
     */
    public function put($url, $data = [])
    {
        $response = $this->getResponse(OAUTH_HTTP_METHOD_PUT, $url, $data);
        return json_decode($response);
    }

    /**
     * @param string $url
     * @return \stdClass
     * @throws JiraApiException
     * @throws \Exception
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
     * @throws JiraApiException
     * @throws \Exception
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
            if ($e->getCode() == 401) {
                $oauthAuthUrl = $this->fetchOAuthRequestToken();
                $message = 'Invalid Ticketsystem Token (Jira) you\'re going to be forwarded';
            } else {
                $message = 'JiraException: ' . $e->getMessage(); // . ' ' . var_dump($this->oAuth->debugInfo);
            }

            throw new JiraApiException($message, $e->getCode(), $oauthAuthUrl);
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
        return $this->ticketSystem->getUrl();
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
}
