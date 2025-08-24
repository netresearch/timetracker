<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\ActivityRepository::class)]
#[ORM\Table(name: 'activities')]
class Activity
{
    /**
     * @var \Doctrine\Common\Collections\Collection<int, Entry>
     */
    #[ORM\OneToMany(targetEntity: \Entry::class, mappedBy: 'activity')]
    protected $entriesRelation;

    /**
     * @var \Doctrine\Common\Collections\Collection<int, Preset>
     */
    #[ORM\OneToMany(targetEntity: \Preset::class, mappedBy: 'activity')]
    protected $presetsRelation;

    public const SICK = 'Krank';

    public const HOLIDAY = 'Urlaub';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected ?int $id = null;

    #[ORM\Column(type: 'string', length: 50)]
    protected ?string $name = null;

    #[ORM\Column(name: 'needs_ticket', type: 'boolean')]
    protected ?bool $needsTicket = null;

    #[ORM\Column(name: 'factor', type: 'float')]
    protected ?float $factor = null;

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
        return self::SICK === $this->getName();
    }

    /**
     * Returns true if activity is holiday.
     */
    public function isHoliday(): bool
    {
        return self::HOLIDAY === $this->getName();
    }
}
