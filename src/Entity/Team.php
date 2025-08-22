<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\TeamRepository")
 * @ORM\Table(name="teams")
 * @ORM\HasLifecycleCallbacks
 */
class Team
{
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    public $customers;

    /**
     * @ORM\ManyToMany(targetEntity="Customer", mappedBy="teams")
     * @var \Doctrine\Common\Collections\Collection<int, Customer>
     */
    protected $customersRelation;
    /**
     * @ORM\Id
     *
     * @ORM\Column (type="integer")
     *
     * @ORM\GeneratedValue (strategy="AUTO")
     *
     * @var null|string
     */
    protected $id;

    /**
     * @ORM\Column (type="string", length=31)
     *
     * @var null|string
     */
    protected $name;

    /**
     * @ORM\ManyToOne (targetEntity="User", inversedBy="leadTeams")
     *
     * @ORM\JoinColumn (name="lead_user_id", referencedColumnName="id")
     *
     * @var User|null
     */
    protected $leadUser;

    /**
     * @ORM\ManyToMany(targetEntity="User", mappedBy="teams")
     * @var \Doctrine\Common\Collections\Collection<int, User>
     */
    protected $users;

    /**
     * Get id
     *
     * @return null|string $id
     */
    public function getId(): string|null
    {
        return $this->id;
    }

    /**
     * Set name
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
     * Get name
     *
     * @return null|string $name
     */
    public function getName(): string|null
    {
        return $this->name;
    }

    /**
     * Set lead user
     *
     *
     * @return $this
     */
    public function setLeadUser(User $leadUser): static
    {
        $this->leadUser = $leadUser;
        return $this;
    }

    /**
     * Get lead user
     *
     * @return User|null $leadUser
     */
    public function getLeadUser(): User|null
    {
        return $this->leadUser;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->customers = new \Doctrine\Common\Collections\ArrayCollection();
        $this->customersRelation = new \Doctrine\Common\Collections\ArrayCollection();
        $this->users = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, User>
     */
    public function getUsers()
    {
        return $this->users;
    }

    /**
     * Detach ManyToMany relations so the join rows are removed before deleting team
     *
     * @ORM\PreRemove
     */
    public function preRemove(): void
    {
        if ($this->users) {
            foreach ($this->users as $user) {
                $user->getTeams()->removeElement($this);
            }
        }
    }

    public function addCustomer(Customer $customer): static
    {
        $this->customersRelation[] = $customer;
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Customer>
     */
    public function getCustomers()
    {
        return $this->customersRelation;
    }
}
