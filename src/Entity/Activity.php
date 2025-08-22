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
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    public $entries;
    /**
     * @ORM\OneToMany(targetEntity="Entry", mappedBy="activity")
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    protected $entriesRelation;
    /**
     * @var \Doctrine\Common\Collections\ArrayCollection
     */
    public $presets;
    /**
     * @ORM\OneToMany(targetEntity="Preset", mappedBy="activity")
     * @var \Doctrine\Common\Collections\Collection<int, Preset>
     */
    protected $presetsRelation;
    public const SICK    = 'Krank';

    public const HOLIDAY = 'Urlaub';

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

    public function __construct()
    {
        $this->entries = new ArrayCollection();
        $this->presets = new ArrayCollection();
        $this->entriesRelation = new ArrayCollection();
        $this->presetsRelation = new ArrayCollection();
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Entry>
     */
    public function getEntries()
    {
        return $this->entriesRelation;
    }

    /**
     * @return \Doctrine\Common\Collections\Collection<int, Preset>
     */
    public function getPresets()
    {
        return $this->presetsRelation;
    }

    public function addEntry(Entry $entry): static
    {
        $this->entriesRelation[] = $entry;
        return $this;
    }

    public function removeEntry(Entry $entry): void
    {
        $this->entriesRelation->removeElement($entry);
    }

    public function addPreset(Preset $preset): static
    {
        $this->presetsRelation[] = $preset;
        return $this;
    }

    public function removePreset(Preset $preset): void
    {
        $this->presetsRelation->removeElement($preset);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): string
    {
        return (string) $this->name;
    }

    public function getNeedsTicket(): bool
    {
        return (bool) $this->needsTicket;
    }

    public function getFactor(): float
    {
        return (float) $this->factor;
    }

    public function setFactor(float $factor): static
    {
        $this->factor = $factor;
        return $this;
    }

    public function setNeedsTicket(bool $needsTicket): static
    {
        $this->needsTicket = $needsTicket;

        return $this;
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
}
