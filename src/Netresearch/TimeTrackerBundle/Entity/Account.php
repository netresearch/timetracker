<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="accounts")
 */
class Account
{

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected $name;

    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="account")
     */
    protected $entries;

    public function __construct()
    {
        $this->entries = new ArrayCollection();
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
     * Set id
     * @param int $id
     *
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
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
     * Add entries
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry $entries
     */
    public function addEntries(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
    {
        $this->entries[] = $entries;
    }

    /**
     * Get entries
     *
     * @return \Doctrine\Common\Collections\Collection $entries
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * Add entries
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry $entries
     * @return Account
     */
    public function addEntry(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
    {
        $this->entries[] = $entries;
        return $this;
    }

    /**
     * Remove entries
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry $entries
     */
    public function removeEntrie(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
    {
        $this->entries->removeElement($entries);
    }

    /**
     * Add entries
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry $entries
     * @return Account
     */
    public function addEntrie(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
    {
        $this->entries[] = $entries;

        return $this;
    }
}
