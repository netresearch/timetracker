<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;

/**
 * App\Entity\TicketSystem
 */
#[ORM\Entity(repositoryClass: \App\Repository\TicketSystemRepository::class)]
#[ORM\Table(name: 'ticket_systems')]
class TicketSystem extends Base
{
    public const TYPE_JIRA = 'JIRA';

    public const TYPE_OTRS = 'OTRS';

    /**
     * @var integer $id
     */
    #[ORM\Column(name: 'id', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string $name
     */
    #[ORM\Column(type: 'string', length: 31, unique: true)]
    protected $name;

    /**
     * @var boolean $bookTime;
     */
    #[ORM\Column(name: 'book_time', type: 'boolean', nullable: false, options: ['default' => 0])]
    protected $bookTime = false;

    /**
     * @var string $type;
     */
    #[ORM\Column(type: 'string', length: 15)]
    protected $type;

    /**
     * @var string $url
     */
    #[ORM\Column(type: 'string', length: 255)]
    protected $url;

    /**
     * @var string $ticketUrl
     */
    #[ORM\Column(name: 'ticketurl', type: 'string', length: 255, nullable: false)]
    protected $ticketUrl;

    /**
     * @var string $login
     */
    #[ORM\Column(type: 'string', length: 63)]
    protected $login;

    /**
     * @var string $password
     */
    #[ORM\Column(type: 'string', length: 63)]
    protected $password;

    #[ORM\Column(type: 'text', name: 'public_key')]
    protected string $publicKey = '';

    #[ORM\Column(type: 'text', name: 'private_key')]
    protected string $privateKey = '';

    #[ORM\Column(name: 'oauth_consumer_key', type: 'string', length: 255, nullable: true)]
    protected ?string $oauthConsumerKey = null;

    #[ORM\Column(name: 'oauth_consumer_secret', type: 'string', length: 255, nullable: true)]
    protected ?string $oauthConsumerSecret = null;



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
    public function setName($name): static
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
    public function setBookTime($bookTime): static
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
    public function setType($type): static
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
    public function setUrl($url): static
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
    public function setTicketUrl($ticketUrl): static
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
    public function setLogin($login): static
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
    public function setPassword($password): static
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

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;
        return $this;
    }

    /**
     * Get public key
     *
     * @return string $publicKey
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }


    /**
     * Set private key
     *
     *
     * @return $this
     */
    public function setPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;
        return $this;
    }

    /**
     * Get private key
     *
     * @return string $privateKey
     */
    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    /**
     * @return string
     */
    public function getOauthConsumerKey(): ?string
    {
        return $this->oauthConsumerKey;
    }

    /**
     * @param string $oauthConsumerKey
     * @return $this
     */
    public function setOauthConsumerKey(?string $oauthConsumerKey): static
    {
        $this->oauthConsumerKey = $oauthConsumerKey;
        return $this;
    }

    /**
     * @return string
     */
    public function getOauthConsumerSecret(): ?string
    {
        return $this->oauthConsumerSecret;
    }

    /**
     * @param string $oauthConsumerSecret
     * @return $this
     */
    public function setOauthConsumerSecret(?string $oauthConsumerSecret): static
    {
        $this->oauthConsumerSecret = $oauthConsumerSecret;
        return $this;
    }
}
