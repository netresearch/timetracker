<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ActivityRepository::class)]
#[ORM\Table(name: 'activities')]
class Activity
{
    /**
     * @var Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: Entry::class, mappedBy: 'activity')]
    protected $entriesRelation;

    /**
     * @var Collection<int, Preset>
     */
    #[ORM\OneToMany(targetEntity: Preset::class, mappedBy: 'activity')]
    protected $presetsRelation;

    public const string SICK = 'Krank';

    public const string HOLIDAY = 'Urlaub';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    #[ORM\Column(type: 'string', length: 50)]
    protected string $name = '';

    #[ORM\Column(name: 'needs_ticket', type: 'boolean', options: ['default' => false])]
    protected bool $needsTicket = false;

    #[ORM\Column(name: 'factor', type: 'float', options: ['default' => 1.0])]
    protected float $factor = 1.0;

    public function __construct()
    {
        $this->entriesRelation = new ArrayCollection();
        $this->presetsRelation = new ArrayCollection();
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
        return $this->name;
    }

    public function getNeedsTicket(): bool
    {
        return $this->needsTicket;
    }

    public function getFactor(): float
    {
        return $this->factor;
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
        return self::SICK === $this->getName();
    }

    /**
     * Returns true if activity is holiday.
     */
    public function isHoliday(): bool
    {
        return self::HOLIDAY === $this->getName();
    }

    /**
     * @return Collection<int, Entry>
     */
    public function getEntries(): Collection
    {
        return $this->entriesRelation;
    }

    /**
     * @return Collection<int, Preset>
     */
    public function getPresets(): Collection
    {
        return $this->presetsRelation;
    }

    public function addEntry(Entry $entry): static
    {
        if (!$this->entriesRelation->contains($entry)) {
            $this->entriesRelation->add($entry);
        }

        return $this;
    }

    public function addPreset(Preset $preset): static
    {
        if (!$this->presetsRelation->contains($preset)) {
            $this->presetsRelation->add($preset);
        }

        return $this;
    }

    public function removeEntry(Entry $entry): static
    {
        if ($this->entriesRelation->contains($entry)) {
            $this->entriesRelation->removeElement($entry);
        }

        return $this;
    }

    public function removePreset(Preset $preset): static
    {
        if ($this->presetsRelation->contains($preset)) {
            $this->presetsRelation->removeElement($preset);
        }

        return $this;
    }
}
