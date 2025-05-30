<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use DateTime;

/**
 * A user contract (working hours)
 *
 * @ORM\Entity(repositoryClass="App\Repository\ContractRepository")
 * @ORM\Table(name="contracts")
 */
class Contract implements JsonSerializable
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected int $id;


    /**
     * @ORM\ManyToOne(targetEntity="User", inversedBy="contracts")
     * @ORM\JoinColumn(name="user_id", referencedColumnName="id", nullable=true)
     */
    protected ?User $user = null;


    /**
     * @ORM\Column(type="date", nullable=false)
     */
    protected ?DateTime $start = null;


    /**
     * @ORM\Column(type="date", nullable=false)
     */
    protected ?DateTime $end = null;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_0 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_1 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_2 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_3 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_4 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_5 = 0.0;


    /**
     * @ORM\Column(type="float", nullable=false)
     */
    protected float $hours_6 = 0.0;

    public function __construct()
    {
        $this->start = new DateTime();
        $this->end = new DateTime();
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStart(): ?DateTime
    {
        return $this->start;
    }

    public function setStart(DateTime $start): static
    {
        $this->start = $start;
        return $this;
    }

    public function getEnd(): ?DateTime
    {
        return $this->end;
    }

    public function setEnd(DateTime $end): static
    {
        $this->end = $end;
        return $this;
    }

    public function getHours0(): float
    {
        return $this->hours_0;
    }

    public function setHours0(float $hours_0): static
    {
        $this->hours_0 = $hours_0;
        return $this;
    }

    public function getHours1(): float
    {
        return $this->hours_1;
    }

    public function setHours1(float $hours_1): static
    {
        $this->hours_1 = $hours_1;
        return $this;
    }

    public function getHours2(): float
    {
        return $this->hours_2;
    }

    public function setHours2(float $hours_2): static
    {
        $this->hours_2 = $hours_2;
        return $this;
    }

    public function getHours3(): float
    {
        return $this->hours_3;
    }

    public function setHours3(float $hours_3): static
    {
        $this->hours_3 = $hours_3;
        return $this;
    }

    public function getHours4(): float
    {
        return $this->hours_4;
    }

    public function setHours4(float $hours_4): static
    {
        $this->hours_4 = $hours_4;
        return $this;
    }

    public function getHours5(): float
    {
        return $this->hours_5;
    }

    public function setHours5(float $hours_5): static
    {
        $this->hours_5 = $hours_5;
        return $this;
    }

    public function getHours6(): float
    {
        return $this->hours_6;
    }

    public function setHours6(float $hours_6): static
    {
        $this->hours_6 = $hours_6;
        return $this;
    }

    public function jsonSerialize(): array
    {
        return ['contract' => [
            'id'      => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'start'   => $this->getStart()
                ? $this->getStart()->format('Y-m-d')
                : null,
            'end'     => $this->getEnd()
                ? $this->getEnd()->format('Y-m-d')
                : null,
            'hours_0' => $this->getHours0(),
            'hours_1' => $this->getHours1(),
            'hours_2' => $this->getHours2(),
            'hours_3' => $this->getHours3(),
            'hours_4' => $this->getHours4(),
            'hours_5' => $this->getHours5(),
            'hours_6' => $this->getHours6(),
        ]];
    }
}
