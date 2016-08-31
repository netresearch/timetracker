<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use HWI\Bundle\OAuthBundle\Security\OAuthUtils;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;
use Netresearch\TimeTrackerBundle\Entity\User;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class JiraUserApi
{

    /** @var string */
    protected $tokenSecret;
    /** @var string */
    protected $token;
    /** @var string */
    protected $jira_client_secret;
    /** @var string */
    protected $jira_client_id;
    /** @var string */
    protected $baseUrl;
    /** @var \Circle\RestClientBundle\Services\RestClient */
    protected $restClient;



    /**
     * JiraUserApi constructor.
     *
     * @param User               $user
     * @param TicketSystem       $ticketSystem
     * @param ContainerInterface $serviceContainer
     */
    public function __construct(
        User $user, TicketSystem $ticketSystem, ContainerInterface $serviceContainer
    ) {
        $this->token              = $user->getTicketSystemAccessToken($ticketSystem);
        $this->tokenSecret        = $user->getTicketSystemAccessTokenSecret($ticketSystem);
        $this->jira_client_secret = $serviceContainer->getParameter('jira_client_secret');
        $this->jira_client_id     = $serviceContainer->getParameter('jira_client_id');
        $this->restClient         = $serviceContainer->get('circle.restclient');
        $this->baseUrl            = $ticketSystem->getUrl();
    }



    /**
     * Execute GET request and return response as simple object.
     *
     * @param string $url
     * @return \stdClass
     */
    public function get($url)
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('GET', $url);
        $content = json_decode($response->getContent());
        return $content;
    }



    /**
     * Execute POST request and return response as simple object.
     *
     * @param  string $url
     * @param  array  $data
     * @return \stdClass
     */
    public function post($url, array $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('POST', $url, $data);
        $content = json_decode($response->getContent());
        return $content;
    }



    /**
     * Execute PUT request and return response as simple object.
     *
     * @param  string $url
     * @param  array  $data
     * @return \stdClass
     */
    public function put($url, array $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('PUT', $url, $data);
        $content = json_decode($response->getContent());
        return $content;
    }



    /**
     * Execute DELETE request and return response HTTP status.
     *
     * @param string $url
     * @return integer Response HTTP status
     */
    public function delete($url)
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('DELETE', $url);
        $content = json_decode($response->getStatusCode());
        return $content;
    }



    /**
     * Send request and return response.
     *
     * @param string $method
     * @param string $url
     * @param array  $data
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function getResponse($method, $url, array $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $requestUrl = $this->baseUrl . $url;

        try {
            switch ($method){
                case 'GET':
                    $response = $this->restClient->get(
                        $requestUrl,
                        $this->getCurlParameters($requestUrl, 'GET')
                    );
                    break;
                case 'POST':
                    $response = $this->restClient->post(
                        $requestUrl,
                        json_encode($data),
                        $this->getCurlParameters($requestUrl, 'POST')
                    );
                    break;
                case 'PUT':
                    $response = $this->restClient->put(
                        $requestUrl,
                        json_encode($data),
                        $this->getCurlParameters($requestUrl, 'PUT')
                    );
                    break;
                case 'DELETE':
                    $response = $this->restClient->delete(
                        $requestUrl,
                        $this->getCurlParameters($requestUrl, 'DELETE')
                    );
                    break;
            }
        } catch (\Exception $e){
            throw new Exception("JIRA ticket system could not be accessed.");
        }

        $this->evaluateStatusCode($response->getStatusCode());

        return $response;
    }



    /**
     * Return curl options.
     *
     * @param string $url
     * @param string $method
     * @return array
     */
    protected function getCurlParameters($url, $method)
    {
        return [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $this->getAuthHeaderEntry($url, $method)
            ]
        ];
    }



    /**
     * Returns OAuth HTTP header Authorization:.
     *
     * @param string $url
     * @param string $method
     * @param array  $extraParameters
     * @return string
     */
    protected function getAuthHeaderEntry(
        $url, $method = 'GET', array $extraParameters = []
    ) {
        $parameters = array_merge(array(
            'oauth_consumer_key'     => $this->jira_client_id,
            'oauth_timestamp'        => time(),
            'oauth_nonce'            => $this->generateNonce(),
            'oauth_version'          => '1.0',
            'oauth_signature_method' => 'RSA-SHA1',
            'oauth_token'            => $this->token,
        ), $extraParameters);

        $parameters['oauth_signature'] = OAuthUtils::signRequest(
            $method,
            $url,
            $parameters,
            $this->jira_client_secret,
            $this->tokenSecret,
            OAuthUtils::SIGNATURE_METHOD_RSA
        );

        foreach ($parameters as $key => $value) {
            $parameters[$key] = $key . '="' . rawurlencode($value) . '"';
        }

        return 'Authorization: OAuth ' . implode(', ', $parameters);
    }



    /**
     * Returns random nonce string.
     *
     * @return string
     */
    protected function generateNonce()
    {
        return md5(microtime(true) . uniqid('', true));
    }



    /**
     * Evaluates status code and throws appropriate exception if required.
     *
     * @param number $statusCode
     */
    protected function evaluateStatusCode($statusCode)
    {
        if ($statusCode == 401) {
            throw new AccessDeniedHttpException();
        } elseif (preg_match('/^2\d\d$/', $statusCode) ==! 1) {
            throw new Exception("Error on requesting JIRA ticket system (status code $statusCode )");
        }
    }
}
