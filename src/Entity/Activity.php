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
    protected ?int $id = null;

    /**
     * @ORM\Column(type="string", length=50)
     */
    protected ?string $name = null;

    /**
     * @ORM\Column(name="needs_ticket", type="boolean")
     */
    protected ?bool $needsTicket = null;

    /**
     * @ORM\Column(name="factor", type="float")
     */
    protected ?float $factor = null;

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

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
		return $this;
	}

    public function getName(): string
    {
        return $this->name;
    }

    public function setNeedsTickets(bool $needsTicket): self
    {
        $this->needsTicket = $needsTicket;
		return $this;
    }

    public function getNeedsTicket(): bool
    {
        return $this->needsTicket;
	}

    public function getFactor(): float
    {
        return $this->factor;
	}

    public function setFactor(float $factor): self
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

    public function setNeedsTicket(bool $needsTicket): self
    {
        $this->needsTicket = $needsTicket;

        return $this;
    }

    public function addEntry(Entry $entry): static
    {
        $this->entries[] = $entry;
        return $this;
    }

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

    public function addPreset(Preset $preset): static
    {
        $this->presets[] = $preset;
        return $this;
    }

    public function removePreset(Preset $preset): void
    {
        $this->presets->removeElement($preset);
    }
}
