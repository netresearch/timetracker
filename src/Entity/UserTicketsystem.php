<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

use App\Model\Base as Base;

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
     */
    protected $id;


    /**
     * @ORM\ManyToOne(targetEntity="TicketSystem")
     * @ORM\JoinColumn(name="ticket_system_id", referencedColumnName="id", nullable=true)
     */
    protected $ticketSystem;


    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="userTicketsystems")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=true)
     */
    protected $user;


    /**
     * @ORM\Column(name="access_token", type="string", length=50)
     */
    protected $accessToken;


    /**
     * @ORM\Column(name="token_secret", type="string", length=50)
     */
    protected $tokenSecret;


    /**
     * @ORM\Column(name="avoid_connection", type="boolean", options={"default"=false})
     */
    protected $avoidConnection;


    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return TicketSystem
     */
    public function getTicketSystem()
    {
        return $this->ticketSystem;
    }

    /**
     * @param TicketSystem $ticketSystem
     * @return $this
     */
    public function setTicketSystem(TicketSystem $ticketSystem)
    {
        $this->ticketSystem = $ticketSystem;
        return $this;
    }

    /**
     * @return User
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * @param User $user
     * @return $this
     */
    public function setUser(User $user)
    {
        $this->user = $user;
        return $this;
    }

    /**
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @param string $accessToken
     * @return $this
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * @return string
     */
    public function getTokenSecret()
    {
        return $this->tokenSecret;
    }

    /**
     * @param string $tokenSecret
     * @return $this
     */
    public function setTokenSecret($tokenSecret)
    {
        $this->tokenSecret = $tokenSecret;
        return $this;
    }

    /**
     * @return boolean
     */
    public function getAvoidConnection()
    {
        return ($this->avoidConnection == 1);
    }

    /**
     * @param boolean $avoidConnection
     * @return $this
     */
    public function setAvoidConnection($avoidConnection)
    {
        $this->avoidConnection = ($avoidConnection? 1 : 0);
        return $this;
    }
}
