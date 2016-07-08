<?php

namespace Netresearch\TimeTrackerBundle\Helper;

/*
 * Client for JIRA REST API
 */
class JiraClient
{
    /**
     * @var string[] JIRA API credentials.
     */
    protected $arAuth = null;

    /**
     * @var string JIRA API endpoint URL.
     */
    protected $strEndpoint = '';

    /**
     * @var bool Debugging switch.
     */
    protected $bDebug = false;

    /**
     * @var resource curl handler.
     */
    protected $curl = null;



    /**
     * JiraClient constructor.
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\TicketSystem $ticketSystem
     */
    public function __construct(
        \Netresearch\TimeTrackerBundle\Entity\TicketSystem $ticketSystem
    ) {
        $this->arAuth['user'] = $ticketSystem->getLogin();
        $this->arAuth['pass'] = $ticketSystem->getPassword();
        $this->strEndpoint = $ticketSystem->getUrl();
    }



    /**
     * send request to specified host
     *
     * @param string $strMethod
     * @param string $strUrl
     * @param array  $arData
     * @param string $strUser
     * @return array
     * @throws \Exception
     */
    public function api(
        $strMethod = 'GET', $strUrl, $arData = array(), $strUser = ''
    ) {
        $strResult = $this->sendRequest($strMethod, $strUrl, $arData, $strUser);

        if (strlen($strResult)) {
            $arResult = json_decode($strResult, true);
            if (! is_array($arResult)) {
                throw new \Exception("JIRA Rest server returns unexpected result: " . $arResult);
            }

            return $arResult;

        } else {
            return false;
        }
    }



    /**
     * Send request to the JIRA API.
     *
     * @param string $method  HTTP request method
     * @param string $url     Target URL
     * @param array  $arData  Request data
     * @param string $strUser Optional JIRA user name
     *
     * @return string
     * @throws \Exception
     */
    public function sendRequest($method, $url, array $arData = array(), $strUser = null)
    {
        $this->initCurl();

        switch ($method) {
        case "GET":
            $url .= "?" . http_build_query($arData);
            break;
        case "POST":
            $this->setOpt(CURLOPT_POST, 1);
            $this->setOpt(CURLOPT_POSTFIELDS, json_encode($arData));
            break;
        case "DELETE":
            $this->setOpt(CURLOPT_CUSTOMREQUEST, "DELETE");
            break;
        case "PUT":
            $this->setOpt(CURLOPT_CUSTOMREQUEST, "PUT");
            $this->setOpt(CURLOPT_POSTFIELDS, json_encode($arData));
            break;
        }

        $arHeaders = array();
        $arHeaders[] = 'Content-Type: application/json;charset=UTF-8';
        if ($strUser) {
            $arHeaders[] = 'sudo: ' . $strUser;
        }

        $this->setOpt(CURLOPT_URL, $this->strEndpoint . $url);

        $this->setOpt(CURLOPT_HTTPHEADER, $arHeaders);

        return $this->exec();
    }



    /**
     * Execute request to JIRA API.
     *
     * @return string
     * @throws \Exception
     */
    protected function exec()
    {
        $strResult = curl_exec($this->curl);

        $errorNumber = curl_errno($this->curl);
        if ($errorNumber > 0) {
            throw new \Exception(
                sprintf(
                    'JIRA request failed, curl says: code = %s, "%s"',
                    $errorNumber, curl_error($this->curl)
                )
            );
        }

        if (curl_getinfo($this->curl, CURLINFO_HTTP_CODE) == 401) {
            throw new \Exception("Unauthorized: contact your Admin.");
        }

        // if empty result and status != "204 No Content"
        if (empty($strResult)
            && !in_array(curl_getinfo($this->curl, CURLINFO_HTTP_CODE), array(201, 204))
        ) {
            throw new \Exception(
                'JIRA REST API returned empty result and HTTP status: '
                . curl_getinfo($this->curl, CURLINFO_HTTP_CODE)
                . ' for ' . curl_getinfo($this->curl, CURLINFO_EFFECTIVE_URL)
            );
        }

        return $strResult;
    }



    /**
     * Initialize curl handler.
     *
     * @return void
     */
    protected function initCurl()
    {
        if (null !== $this->curl) {
            return;
        }

        $this->curl = curl_init();

        $this->setOpt(CURLOPT_HEADER, 0);
        $this->setOpt(CURLOPT_RETURNTRANSFER, 1);

        if (null !== $this->arAuth) {
            $this->setOpt(
                CURLOPT_USERPWD,
                sprintf("%s:%s", $this->arAuth['user'], $this->arAuth['pass'])
            );
        }

        $this->setOpt(CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
        $this->setOpt(CURLOPT_SSL_VERIFYPEER, false);
        $this->setOpt(CURLOPT_SSL_VERIFYHOST, 0);
        $this->setOpt(CURLOPT_VERBOSE, $this->bDebug);

        //$this->setOpt(CURLOPT_HEADER, true);
        //$this->setOpt(CURLINFO_HEADER_OUT, true);
    }



    /**
     * Set curl options.
     *
     * @see curl_setopt()
     * @param integer $option
     * @param mixed   $value
     * @return void
     */
    protected function setOpt($option, $value)
    {
        if (null === $this->curl) {
            $this->initCurl();
        }

        curl_setopt($this->curl, $option, $value);
    }



    /**
     * Sets HTTP proxy for curl handler.
     *
     * @param string $strHttpProxy HTTP proxy
     */
    public function setProxy($strHttpProxy)
    {
        $this->setOpt(CURLOPT_PROXY, $strHttpProxy);
    }
}
