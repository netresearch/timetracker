<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Netresearch\TimeTrackerBundle\Model\Base as Base;

/**
 * Netresearch\TimeTrackerBundle\Entity\TicketSystem
 *
 * @ORM\Entity
 * @ORM\Table(name="ticket_systems")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\TicketSystemRepository")
 */
class TicketSystem extends Base
{
    const TYPE_JIRA = 'JIRA';
    const TYPE_OTRS = 'OTRS';

    /**
     * @var integer $id
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @var string $name
     * @ORM\Column(type="string")
     */
    protected $name;

    /**
     * @var boolean $bookTime;
     * @ORM\Column(name="book_time", type="integer", nullable=false)
     */
    protected $bookTime;

    /**
     * @var string $type;
     * @ORM\Column(type="string")
     */
    protected $type;

    /**
     * @var string $url
     * @ORM\Column(type="string")
     */
    protected $url;

    /**
     * @var string $ticketUrl
     * @ORM\Column(type="string")
     */
    protected $ticketUrl;

    /**
    /**
     * @var string $login
     * @ORM\Column(type="string")
     */
    protected $login;

    /**
     * @var string $password
     * @ORM\Column(type="string")
     */
    protected $password;

    /**
     * @var string $publicKey
     * @ORM\Column(type="string", name="public_key")
     */
    protected $publicKey;

    /**
     * @var string $privateKey
     * @ORM\Column(type="string", name="private_key")
     */
    protected $privateKey;

    /**
     * @var string $oauthConsumerKey
     * @ORM\Column(type="string", name="oauth_consumer_key")
     */
    protected $oauthConsumerKey;

    /**
     * @var string $oauthConsumerSecret
     * @ORM\Column(type="string", name="oauth_consumer_secret")
     */
    protected $oauthConsumerSecret;



    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }


    /**
     * Set bookTime
     *
     * @param boolean $bookTime
     *
     * @return $this
     */
    public function setBookTime($bookTime)
    {
        $this->bookTime = $bookTime;
        return $this;
    }

    /**
     * Get bookTime
     *
     * @return boolean $bookTime
     */
    public function getBookTime()
    {
        return $this->bookTime;
    }


    /**
     * Set type
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * Get type
     *
     * @return string $type
     */
    public function getType()
    {
        return $this->type;
    }


    /**
     * Set url
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl($url)
    {
        $this->url = $url;
        return $this;
    }

    /**
     * Get url
     *
     * @return string $url
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Set the ticket url.
     *
     * @param string $ticketUrl
     *
     * @return $this
     */
    public function setTicketUrl($ticketUrl)
    {
        $this->ticketUrl = $ticketUrl;
        return $this;
    }

    /**
     * Get url pointing to a ticket
     *
     * @return string $ticketUrl
     */
    public function getTicketUrl()
    {
        return $this->ticketUrl;
    }

    /**
     * Set login
     *
     * @param string $login
     *
     * @return $this
     */
    public function setLogin($login)
    {
        $this->login = $login;
        return $this;
    }

    /**
     * Get login
     *
     * @return string $login
     */
    public function getLogin()
    {
        return $this->login;
    }


    /**
     * Set password
     *
     * @param string $password
     *
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }

    /**
     * Get password
     *
     * @return string $password
     */
    public function getPassword()
    {
        return $this->password;
    }


    /**
     * Set public key
     *
     * @param string $publicKey
     *
     * @return $this
     */
    public function setPublicKey($publicKey)
    {
        $this->publicKey = $publicKey;
        return $this;
    }

    /**
     * Get public key
     *
     * @return string $publicKey
     */
    public function getPublicKey()
    {
        return $this->publicKey;
    }


    /**
     * Set private key
     *
     * @param string $privateKey
     *
     * @return $this
     */
    public function setPrivateKey($privateKey)
    {
        $this->privateKey = $privateKey;
        return $this;
    }

    /**
     * Get private key
     *
     * @return string $privateKey
     */
    public function getPrivateKey()
    {
        return $this->privateKey;
    }

    /**
     * @return string
     */
    public function getOauthConsumerKey()
    {
        return $this->oauthConsumerKey;
    }

    /**
     * @param string $oauthConsumerKey
     * @return $this
     */
    public function setOauthConsumerKey($oauthConsumerKey)
    {
        $this->oauthConsumerKey = $oauthConsumerKey;
        return $this;
    }

    /**
     * @return string
     */
    public function getOauthConsumerSecret()
    {
        return $this->oauthConsumerSecret;
    }

    /**
     * @param string $oauthConsumerSecret
     * @return $this
     */
    public function setOauthConsumerSecret($oauthConsumerSecret)
    {
        $this->oauthConsumerSecret = $oauthConsumerSecret;
        return $this;
    }
}

