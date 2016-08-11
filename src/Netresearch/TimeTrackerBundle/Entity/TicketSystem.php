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
     * @var string $ticketurl
     * @ORM\Column(type="string")
     */
    protected $ticketurl;

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
     * @var string $ticketUrl
     * @ORM\Column(type="string", name="ticketurl")
     */
    protected $ticketUrl;


    
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
     * @param string $ticketurl
     *
     * @return $this
     */
    public function setTicketUrl($strTicketUrl)
    {
        $this->ticketurl = $strTicketUrl;
        return $this;
    }

    /**
     * Get url pointing to a ticket
     *
     * @return string $ticketurl
     */
    public function getTicketUrl()
    {
        return $this->ticketurl;
    }

    /**
     * Set login
     *
     * @param string $login
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
    public function getTicketUrl()
    {
        return $this->ticketUrl;
    }

    /**
     * @param string $ticketUrl
     * @return $this
     */
    public function setTicketUrl($ticketUrl)
    {
        $this->ticketUrl = $ticketUrl;
        return $this;
    }

}
