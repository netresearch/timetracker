<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\TeamRepository")
 * @ORM\Table(name="teams")
 */
class Team
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=31)
     */
    protected $name;

    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="leadTeams")
     * @ORM\JoinColumn(name="lead_user_id", referencedColumnName="id")
     */
    protected $leadUser;

    /**
     * @ORM\ManyToMany(targetEntity="Customer", mappedBy="teams")
     * @ORM\JoinTable(name="teams_customers",
     *     joinColumns={@ORM\JoinColumn(name="team_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="customer_id", referencedColumnName="id")}
     * )
     */
    protected $customers;

    /**
     * @ORM\ManyToMany(targetEntity="User", mappedBy="teams")
     * @ORM\JoinTable(name="teams_users",
     *     joinColumns={@ORM\JoinColumn(name="team_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="user_id", referencedColumnName="id")}
     * )
     */
    protected $users;

    /**
     * Set id
     *
     * @param string $id
     *
     * @return $this
     */
    public function setId($id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Get id
     *
     * @return integer $id
     */
    public function getId()
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
     * @return string $name
     */
    public function getName()
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
     * @return User $leadUser
     */
    public function getLeadUser()
    {
        return $this->leadUser;
    }

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->customers = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Add customers
     */
    public function addCustomer(Customer $customer): static
    {
        $this->customers[] = $customer;
        return $this;
    }

    /**
     * Remove customers
     */
    public function removeCustomer(Customer $customer): void
    {
        $this->customers->removeElement($customer);
    }

    /**
     * Get customers
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getCustomers()
    {
        return $this->customers;
    }
}
