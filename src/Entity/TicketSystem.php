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

    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING)]
    protected $name;

    #[ORM\Column(name: 'book_time', type: Types::INTEGER, options: ["default" => 0])]
    protected $bookTime = 0;

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $type = '';

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $url = '';

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $ticketUrl = '';

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $login = '';

    #[ORM\Column(type: Types::STRING, options: ["default" => ''])]
    protected $password = '';

    #[ORM\Column(type: Types::STRING, name: 'public_key', options: ["default" => ''])]
    protected $publicKey = '';

    #[ORM\Column(type: Types::STRING, name: 'private_key', options: ["default" => ''])]
    protected $privateKey = '';

    #[ORM\Column(type: Types::STRING, name: 'oauth_consumer_key', options: ["default" => ''])]
    protected $oauthConsumerKey = '';

    #[ORM\Column(type: Types::STRING, name: 'oauth_consumer_secret', options: ["default" => ''])]
    protected $oauthConsumerSecret = '';

    #[ORM\OneToMany(targetEntity: 'UserTicketsystem', mappedBy: 'ticketSystem')]
    protected $userTicketsystems;

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setBookTime(bool $bookTime): static
    {
        $this->bookTime = $bookTime;

        return $this;
    }

    public function getBookTime(): bool
    {
        return $this->bookTime;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setTicketUrl(string $ticketUrl): static
    {
        $this->ticketUrl = $ticketUrl;

        return $this;
    }

    public function getTicketUrl(): string
    {
        return $this->ticketUrl;
    }

    public function setLogin(string $login): static
    {
        $this->login = $login;

        return $this;
    }

    public function getLogin(): string
    {
        return $this->login;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPublicKey(string $publicKey): static
    {
        $this->publicKey = $publicKey;

        return $this;
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    public function setPrivateKey(string $privateKey): static
    {
        $this->privateKey = $privateKey;

        return $this;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function getOauthConsumerKey(): string
    {
        return $this->oauthConsumerKey;
    }

    public function setOauthConsumerKey(string $oauthConsumerKey): static
    {
        $this->oauthConsumerKey = $oauthConsumerKey;

        return $this;
    }

    public function getOauthConsumerSecret(): string
    {
        return $this->oauthConsumerSecret;
    }

    public function setOauthConsumerSecret(string $oauthConsumerSecret): static
    {
        $this->oauthConsumerSecret = $oauthConsumerSecret;

        return $this;
    }
}
