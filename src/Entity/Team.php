<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\TeamRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'teams')]
class Team
{
    /**
     * @var \Doctrine\Common\Collections\Collection<int, Customer>
     */
    #[ORM\ManyToMany(targetEntity: Customer::class, mappedBy: 'teams')]
    protected $customersRelation;

    /**
     * @var string|null
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    /**
     * @var string|null
     */
    #[ORM\Column(type: 'string', length: 31)]
    protected $name;

    /**
     * @var User|null
     */
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'leadTeams')]
    #[ORM\JoinColumn(name: 'lead_user_id', referencedColumnName: 'id')]
    protected $leadUser;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'teams')]
    protected $users;

    /**
     * Get id.
     *
     * @return string|null $id
     */
    public function getId(): ?int
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
    public function setName($name): static
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
     * @return \Doctrine\Common\Collections\Collection<int, Customer>
     */
    public function getCustomers(): \Doctrine\Common\Collections\Collection
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
        $this->customersRelation = new \Doctrine\Common\Collections\ArrayCollection();
        $this->users = new \Doctrine\Common\Collections\ArrayCollection();
    }
}
