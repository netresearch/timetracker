<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;

/**
 *
 * @ORM\Entity(repositoryClass="App\Repository\ActivityRepository")
 * @ORM\Table(name="activities")
 */
class Activity
{
    const SICK    = 'Krank';

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

    /**
     * @ORM\OneToMany(targetEntity="Preset", mappedBy="activity")
     */
    protected $presets;

    public function __construct()
    {
    	$this->entries = new ArrayCollection();
        $this->presets = new ArrayCollection();
    }


    /**
     * Set id
     * @param integer $id
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
     * Set needsTicket
     *
     * @param boolean $needsTicket
     *
     * @return $this
     */
    public function setNeedsTickets($needsTicket): static
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
     *
     * @return $this
     */
    public function setFactor($factor): static
    {
        $this->factor = $factor;
		return $this;
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
     * Set needsTicket
     *
     * @param boolean $needsTicket
     */
    public function setNeedsTicket($needsTicket): static
    {
        $this->needsTicket = $needsTicket;

        return $this;
    }

    /**
     * Add entry
     */
    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;
        return $this;
    }

    /**
     * Remove entry
     */
    public function removeEntry(Entry $entry): void
    {
        $this->entries->removeElement($entry);
    }

    /**
     * Returns true if activity is a sick day.
     */
    public function isSick(): bool
    {
        return $this->getName() === self::SICK;
    }

    /**
     * Returns true if activity is holiday.
     */
    public function isHoliday(): bool
    {
        return $this->getName() === self::HOLIDAY;
    }

    /**
     * Get presets
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getPresets()
    {
        return $this->presets;
    }

    /**
     * Add preset
     */
    public function addPreset(Preset $preset): static
    {
        $this->presets[] = $preset;
        return $this;
    }

    /**
     * Remove preset
     */
    public function removePreset(Preset $preset): void
    {
        $this->presets->removeElement($preset);
    }
}
