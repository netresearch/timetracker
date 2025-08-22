<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="users_ticket_systems")
 */
class UserTicketsystem extends Base
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     *
     * @var int|null
     */
    protected $id;


    /**
     * @ORM\ManyToOne (targetEntity="TicketSystem")
     *
     * @ORM\JoinColumn (name="ticket_system_id", referencedColumnName="id", nullable=true)
     */
    protected TicketSystem $ticketSystem;


    /**
     * @ORM\ManyToOne (targetEntity="User", inversedBy="userTicketsystems")
     *
     * @ORM\JoinColumn (name="user_id", referencedColumnName="id", nullable=true)
     */
    protected User $user;


    /**
     * @ORM\Column (name="accesstoken", type="string", length=50)
     *
     * @var string
     */
    protected $accessToken;


    /**
     * @ORM\Column (name="tokensecret", type="string", length=50)
     *
     * @var string
     */
    protected $tokenSecret;


    /**
     * @ORM\Column (name="avoidconnection", type="boolean", options={"default"=false})
     *
     *
     * @psalm-var 0|1
     */
    protected int $avoidConnection;


    /**
     * @return int|null
     */
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

    public function getTicketSystem(): \App\Entity\TicketSystem
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

    public function getUser(): \App\Entity\User
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

    /**
     * @return string
     */
    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    /**
     * @param string $accessToken
     * @return $this
     */
    public function setAccessToken($accessToken): static
    {
        $this->accessToken = (string) $accessToken;
        return $this;
    }

    /**
     * @return string
     */
    public function getTokenSecret(): string
    {
        return $this->tokenSecret;
    }

    /**
     * @param string $tokenSecret
     * @return $this
     */
    public function setTokenSecret($tokenSecret): static
    {
        $this->tokenSecret = (string) $tokenSecret;
        return $this;
    }

    public function getAvoidConnection(): bool
    {
        return ($this->avoidConnection == 1);
    }

    /**
     * @param boolean $avoidConnection
     * @return $this
     */
    public function setAvoidConnection($avoidConnection): static
    {
        $this->avoidConnection = ($avoidConnection ? 1 : 0);
        return $this;
    }
}
