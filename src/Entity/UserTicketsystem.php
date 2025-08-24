<?php

namespace App\Entity;

use App\Model\Base;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users_ticket_systems')]
class UserTicketsystem extends Base
{
    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: \TicketSystem::class)]
    #[ORM\JoinColumn(name: 'ticket_system_id', referencedColumnName: 'id', nullable: true)]
    protected TicketSystem $ticketSystem;

    #[ORM\ManyToOne(targetEntity: \User::class, inversedBy: 'userTicketsystems')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true)]
    protected User $user;

    /**
     * @var string
     */
    #[ORM\Column(name: 'accesstoken', type: 'string', length: 50)]
    protected $accessToken;

    /**
     * @var string
     */
    #[ORM\Column(name: 'tokensecret', type: 'string', length: 50)]
    protected $tokenSecret;

    /**
     * @psalm-var 0|1
     */
    #[ORM\Column(name: 'avoidconnection', type: 'boolean', options: ['default' => false])]
    protected int $avoidConnection;

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return $this
     */
    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTicketSystem(): TicketSystem
    {
        return $this->ticketSystem;
    }

    /**
     * @return $this
     */
    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /**
     * @return $this
     */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @param string $accessToken
     *
     * @return $this
     */
    public function setAccessToken($accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getTokenSecret(): string
    {
        return $this->tokenSecret;
    }

    /**
     * @param string $tokenSecret
     *
     * @return $this
     */
    public function setTokenSecret($tokenSecret): static
    {
        $this->tokenSecret = $tokenSecret;

        return $this;
    }

    public function getAvoidConnection(): bool
    {
        return 1 == $this->avoidConnection;
    }

    /**
     * @param bool $avoidConnection
     *
     * @return $this
     */
    public function setAvoidConnection($avoidConnection): static
    {
        $this->avoidConnection = ($avoidConnection ? 1 : 0);

        return $this;
    }
}
