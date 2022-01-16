<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base as Base;

#[ORM\Entity]
#[ORM\Table(name: 'users_ticket_systems')]
class UserTicketsystem extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\ManyToOne(targetEntity: 'TicketSystem', inversedBy: 'userTicketsystems')]
    protected $ticketSystem;

    #[ORM\ManyToOne(targetEntity: 'User', inversedBy: 'userTicketsystems')]
    protected $user;

    #[ORM\Column(type: Types::STRING, length: 50, options: ["default" => ''])]
    protected $accessToken = '';

    #[ORM\Column(type: Types::STRING, length: 50, options: ["default" => ''])]
    protected $tokenSecret = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ["default" => 0])]
    protected $avoidConnection = false;

    public function getId(): int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getTicketSystem(): TicketSystem
    {
        return $this->ticketSystem;
    }

    public function setTicketSystem(TicketSystem $ticketSystem): static
    {
        $this->ticketSystem = $ticketSystem;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getAccessToken(): string
    {
        return $this->accessToken;
    }

    public function setAccessToken(string $accessToken): static
    {
        $this->accessToken = $accessToken;

        return $this;
    }

    public function getTokenSecret(): string
    {
        return $this->tokenSecret;
    }

    public function setTokenSecret(string $tokenSecret): static
    {
        $this->tokenSecret = $tokenSecret;

        return $this;
    }

    public function getAvoidConnection(): bool
    {
        return $this->avoidConnection;
    }

    public function setAvoidConnection(bool $avoidConnection): static
    {
        $this->avoidConnection = $avoidConnection;

        return $this;
    }
}
