<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 *
 * @ORM\Entity
 * @ORM\Table(name="activities")
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Entity\ActivityRepository")
 */
class Activity
{
    const SICK = 'Krank';
    const HOLIDAY = 'Urlaub';
	
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
     * @ORM\Column(name="needs_ticket", type="boolean")
     */
    protected $needsTicket;
	
    /**
     * @ORM\Column(name="factor", type="float")
     */
    protected $factor;
	
    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="activity")
     */
    protected $entries;

    public function __construct()
    {
    	$this->entries = new ArrayCollection();
    }
	

    /**
     * Set id
     * @param integer $id
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
     * Set needsTicket
     *
     * @param boolean $needsTicket
     */
    public function setNeedsTickets($needsTicket)
    {
        $this->needsTicket = $needsTicket;
		return $this;
    }

    /**
     * Get needsTicket
     *
     * @return boolean $needsTicket
     */
    public function getNeedsTicket()
    {
        return $this->needsTicket;
	}


    /**
     * Get factor
     *
     * @return float $factor
     */
    public function getFactor()
    {
        return $this->factor;
	}


    /**
     * Set factor
     *
     * @param float $factor
     */
    public function setFactor($factor)
    {
        $this->factor = $factor;
		return $this;
    }

    /**
     * Add entries
     *
     * @param Netresearch\TimeTrackerBundle\Entity\Entry $entries
     */
    public function addEntries(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
    {
		$this->entries[] = $entries;
		return $this;
    }

    /**
     * Get entries
     *
     * @return Doctrine\Common\Collections\Collection $entries
     */
    public function getEntries()
    {
        return $this->entries;
    }

    /**
     * Set needsTicket
     *
     * @param boolean $needsTicket
     * @return Activity
     */
    public function setNeedsTicket($needsTicket)
    {
        $this->needsTicket = $needsTicket;
    
        return $this;
    }

    /**
     * Add entries
     *
     * @param \Netresearch\TimeTrackerBundle\Entity\Entry $entries
     * @return Activity
     */
    public function addEntrie(\Netresearch\TimeTrackerBundle\Entity\Entry $entries)
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
     * Returns true if activity is a sick day.
     *
     * @return bool
     */
    public function isSick()
    {
        if ($this->getName() === self::SICK) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if activity is holiday.
     *
     * @return bool
     */
    public function isHoliday()
    {
        if ($this->getName() === self::HOLIDAY) {
            return true;
        }

        return false;
    }
}