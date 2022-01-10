<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\TicketSystemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base as Base;

/**
 * App\Entity\TicketSystem.
 */
#[ORM\Entity(repositoryClass: TicketSystemRepository::class)]
#[ORM\Table(name: 'ticket_systems')]
class TicketSystem extends Base
{
    final public const TYPE_JIRA = 'JIRA';
    final public const TYPE_OTRS = 'OTRS';
    /**
     * @var int $id
     */
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;
    /**
     * @var string $name
     */
    #[ORM\Column(type: Types::STRING)]
    protected $name;

    /**
     * @var bool $bookTime;
     */
    #[ORM\Column(name: 'book_time', type: Types::INTEGER, nullable: false)]
    protected $bookTime;

    /**
     * @var string $type;
     */
    #[ORM\Column(type: Types::STRING)]
    protected $type;

    /**
     * @var string $url
     */
    #[ORM\Column(type: Types::STRING)]
    protected $url;

    /**
     * @var string $ticketUrl
     */
    #[ORM\Column(type: Types::STRING)]
    protected $ticketUrl;

    /**
     * @var string $login
     */
    #[ORM\Column(type: Types::STRING)]
    protected $login;

    /**
     * @var string $password
     */
    #[ORM\Column(type: Types::STRING)]
    protected $password;

    /**
     * @var string $publicKey
     */
    #[ORM\Column(type: Types::STRING, name: 'public_key')]
    protected $publicKey;

    /**
     * @var string $privateKey
     */
    #[ORM\Column(type: Types::STRING, name: 'private_key')]
    protected $privateKey;

    /**
     * @var string $oauthConsumerKey
     */
    #[ORM\Column(type: Types::STRING, name: 'oauth_consumer_key')]
    protected $oauthConsumerKey;

    /**
     * @var string $oauthConsumerSecret
     */
    #[ORM\Column(type: Types::STRING, name: 'oauth_consumer_secret')]
    protected $oauthConsumerSecret;

    #[ORM\OneToMany(targetEntity: 'UserTicketsystem', mappedBy: 'ticketSystem')]
    protected $userTicketsystems;

    /**
     * Get id.
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set bookTime.
     *
     * @param bool $bookTime
     *
     * @return $this
     */
    public function setBookTime(bool $bookTime)
    {
        $this->bookTime = $bookTime;

        return $this;
    }

    /**
     * Get bookTime.
     *
     * @return bool $bookTime
     */
    public function getBookTime(): bool
    {
        return $this->bookTime;
    }

    /**
     * Set type.
     *
     * @param string $type
     *
     * @return $this
     */
    public function setType(string $type)
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Get type.
     *
     * @return string $type
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set url.
     *
     * @param string $url
     *
     * @return $this
     */
    public function setUrl(string $url)
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Get url.
     *
     * @return string $url
     */
    public function getUrl(): string
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
    public function setTicketUrl(string $ticketUrl)
    {
        $this->ticketUrl = $ticketUrl;

        return $this;
    }

    /**
     * Get url pointing to a ticket.
     *
     * @return string $ticketUrl
     */
    public function getTicketUrl(): string
    {
        return $this->ticketUrl;
    }

    /**
     * Set login.
     *
     * @param string $login
     *
     * @return $this
     */
    public function setLogin(string $login)
    {
        $this->login = $login;

        return $this;
    }

    /**
     * Get login.
     *
     * @return string $login
     */
    public function getLogin(): string
    {
        return $this->login;
    }

    /**
     * Set password.
     *
     * @param string $password
     *
     * @return $this
     */
    public function setPassword(string $password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * Get password.
     *
     * @return string $password
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Set public key.
     *
     * @param string $publicKey
     *
     * @return $this
     */
    public function setPublicKey(string $publicKey)
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    /**
     * Get public key.
     *
     * @return string $publicKey
     */
    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Set private key.
     *
     * @param string $privateKey
     *
     * @return $this
     */
    public function setPrivateKey(string $privateKey)
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    /**
     * Get private key.
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
    public function getOauthConsumerKey(): string
    {
        return $this->oauthConsumerKey;
    }

    /**
     * @param string $oauthConsumerKey
     *
     * @return $this
     */
    public function setOauthConsumerKey(string $oauthConsumerKey)
    {
        $this->oauthConsumerKey = $oauthConsumerKey;

        return $this;
    }

    /**
     * @return string
     */
    public function getOauthConsumerSecret(): string
    {
        return $this->oauthConsumerSecret;
    }

    /**
     * @param string $oauthConsumerSecret
     *
     * @return $this
     */
    public function setOauthConsumerSecret(string $oauthConsumerSecret)
    {
        $this->oauthConsumerSecret = $oauthConsumerSecret;

        return $this;
    }
}
