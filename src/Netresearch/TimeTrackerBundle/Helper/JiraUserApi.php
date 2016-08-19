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
    protected $tokensecret;
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


    public function __construct(User $user, TicketSystem $ticketSystem, ContainerInterface $serviceContainer)
    {
        $this->token = $user->getTicketSystemAccessToken($ticketSystem);
        $this->tokensecret = $user->getTicketSystemAccessTokenSecret($ticketSystem);
        $this->jira_client_secret = $serviceContainer->getParameter('jira_client_secret');
        $this->jira_client_id = $serviceContainer->getParameter('jira_client_id');
        $this->restClient = $serviceContainer->get('circle.restclient');
        $this->baseUrl = $ticketSystem->getUrl();
    }


    /**
     * @param $url
     * @return Object
     * @throws AccessDeniedHttpException
     */
    public function get($url)
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('GET', $url);
        $content = json_decode($response->getContent());
        return $content;
    }


    public function post($url, $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('POST', $url, $data);
        $content = json_decode($response->getContent());
        return $content;
    }



    /**
     * @param  string $url
     * @param  array $data
     * @return stdClass
     */
    public function put($url, $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('PUT', $url, $data);
        $content = json_decode($response->getContent());
        return $content;
    }


    public function delete($url)
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $response = $this->getResponse('DELETE', $url);
        $content = json_decode($response->getStatusCode());
        return $content;
    }


    protected function getResponse($method, $url, $data = [])
    {
        /** @var  $response \Symfony\Component\HttpFoundation\Response */
        $requestUrl = $this->baseUrl.$url;

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
            throw new Exception("Jira Ticketsystem could not be accessed");
        }

        $this->evaluateStatusCode($response->getStatusCode());

        return $response;
    }


    protected function getCurlParameters($url, $method)
    {
        return [
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                $this->getAuthHeaderEntry($url, $method)
            ]
        ];
    }


    protected function getAuthHeaderEntry($url, $method = 'GET', $extraParameters = [])
    {
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
            $this->tokensecret,
            OAuthUtils::SIGNATURE_METHOD_RSA
        );

        foreach ($parameters as $key => $value) {
            $parameters[$key] = $key . '="' . rawurlencode($value) . '"';
        }

        return 'Authorization: OAuth ' . implode(', ', $parameters);
    }


    protected function generateNonce()
    {
        return md5(microtime(true).uniqid('', true));
    }


    protected function evaluateStatusCode($statusCode)
    {
        if ($statusCode == 401) {
            throw new AccessDeniedHttpException();
        } else if(preg_match("/^2\d\d$/", $statusCode) ==! 1){
            throw new Exception("Error on requesting Jira Ticket System (Statuscode $statusCode )");
        }
    }
}
