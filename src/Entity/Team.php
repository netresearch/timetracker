<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\Table(name: 'teams')]
class Team
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: Types::STRING, length: 31)]
    protected $name;

    #[ORM\ManyToOne(targetEntity: 'User')]
    protected $leadUser;

    #[ORM\ManyToMany(targetEntity: 'Customer', inversedBy: 'teams')]
    protected $customers;

    #[ORM\ManyToMany(targetEntity: 'User', mappedBy: 'teams')]
    protected $users;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->customers = new ArrayCollection();
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

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

    public function setLeadUser(?User $leadUser): static
    {
        $this->leadUser = $leadUser;

        return $this;
    }

    public function getLeadUser(): ?User
    {
        return $this->leadUser;
    }

    public function addCustomer(Customer $customers): static
    {
        $this->customers[] = $customers;

        return $this;
    }

    public function removeCustomer(Customer $customers): void
    {
        $this->customers->removeElement($customers);
    }

    public function getCustomers(): Collection
    {
        return $this->customers;
    }
}
