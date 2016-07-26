<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="teams")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\TeamRepository")
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
     * @ORM\ManyToOne(targetEntity="User", inversedBy="teams")
     * @ORM\JoinColumn(name="lead_user_id", referencedColumnName="id")
     */
    protected $leadUser;

    /**
     * @ORM\ManyToMany(targetEntity="Customer", inversedBy="teams")
     */
    protected $customers;

    /**
     * Set id
     *
     * @param string $id
     *
     * @return $this
     */
    public function setId($id)
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
    public function setName($name)
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
     * @param \Netresearch\TimeTrackerBundle\Entity\User $leadUser
     *
     * @return $this
     */
    public function setLeadUser(\Netresearch\TimeTrackerBundle\Entity\User $leadUser)
    {
        $this->leadUser = $leadUser;
        return $this;
    }

    /**
     * Get lead user
     *
     * @return \Netresearch\TimeTrackerBundle\Entity\User $leadUser
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
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Customer $customers
     * @return Team
     */
    public function addCustomer(\Netresearch\TimeTrackerBundle\Entity\Customer $customers)
    {
        $this->customers[] = $customers;
        return $this;
    }

    /**
     * Remove customers
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Customer $customers
     */
    public function removeCustomer(\Netresearch\TimeTrackerBundle\Entity\Customer $customers)
    {
        $this->customers->removeElement($customers);
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
