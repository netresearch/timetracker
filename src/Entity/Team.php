<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TeamRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'teams')]
class Team
{
    /**
     * @var Collection<int, Customer>
     */
    #[ORM\ManyToMany(targetEntity: Customer::class, mappedBy: 'teams')]
    protected $customersRelation;

    /**
     * @var int|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: 'string', length: 31)]
    protected string $name = '';

    /**
     * @var User|null
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'lead_user_id', referencedColumnName: 'id')]
    protected $leadUser;

    /**
     * @var Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'teams')]
    protected $users;

    /**
     * Get id.
     *
     * @return int|null $id
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set name.
     *
     * @return $this
     */
    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     *
     * @return string|null $name
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set lead user.
     *
     * @return $this
     */
    public function setLeadUser(User $leadUser): static
    {
        $this->leadUser = $leadUser;

        return $this;
    }

    /**
     * Get lead user.
     *
     * @return User|null $leadUser
     */
    public function getLeadUser(): ?User
    {
        return $this->leadUser;
    }

    /**
     * @return Collection<int, Customer>
     */
    public function getCustomers(): Collection
    {
        return $this->customersRelation;
    }

    public function addCustomer(Customer $customer): static
    {
        if (!$this->customersRelation->contains($customer)) {
            $this->customersRelation->add($customer);
        }

        return $this;
    }

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->customersRelation = new ArrayCollection();
        $this->users = new ArrayCollection();
    }
}
