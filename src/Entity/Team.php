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
     * Set id.
     *
     * @param string $id
     *
     * @return $this
     */
    public function setId(string $id)
    {
        $this->id = $id;

        return $this;
    }

    /**
     * Get id.
     *
     * @return int $id
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
     * @return string $name
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set lead user.
     *
     * @return $this
     */
    public function setLeadUser(User $leadUser)
    {
        $this->leadUser = $leadUser;

        return $this;
    }

    /**
     * Get lead user.
     *
     * @return User $leadUser
     */
    public function getLeadUser(): User
    {
        return $this->leadUser;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->customers = new ArrayCollection();
    }

    /**
     * Add customers.
     *
     * @return Team
     */
    public function addCustomer(Customer $customers): self
    {
        $this->customers[] = $customers;

        return $this;
    }

    /**
     * Remove customers.
     */
    public function removeCustomer(Customer $customers): void
    {
        $this->customers->removeElement($customers);
    }

    /**
     * Get customers.
     *
     * @return Collection
     */
    public function getCustomers(): Collection
    {
        return $this->customers;
    }
}
