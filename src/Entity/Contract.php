<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A user contract (working hours).
 */
#[ORM\Entity(repositoryClass: \App\Repository\ContractRepository::class)]
#[ORM\Table(name: 'contracts')]
class Contract
{
    #[ORM\ManyToOne(targetEntity: \User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    public User $user;

    /**
     * @var int
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    #[ORM\Column(type: 'date', nullable: false)]
    protected \DateTime $start;

    #[ORM\Column(type: 'date', nullable: true)]
    protected ?\DateTime $end = null;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_0;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_1;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_2;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_3;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_4;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_5;

    /**
     * @var float
     */
    #[ORM\Column(type: 'float', nullable: false)]
    protected $hours_6;

    /**
     * Get id.
     *
     * @return int $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set user.
     *
     * @return $this
     */
    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getStart(): \DateTime
    {
        return $this->start;
    }

    /**
     * @return $this
     */
    public function setStart(\DateTime $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?\DateTime
    {
        return $this->end;
    }

    /**
     * @return $this
     */
    public function setEnd(?\DateTime $dateTimed): static
    {
        $this->end = $dateTimed;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours0()
    {
        return $this->hours_0;
    }

    /**
     * @param float $hours_0
     *
     * @return $this
     */
    public function setHours0($hours_0): static
    {
        $this->hours_0 = $hours_0;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours1()
    {
        return $this->hours_1;
    }

    /**
     * @param float $hours_1
     *
     * @return $this
     */
    public function setHours1($hours_1): static
    {
        $this->hours_1 = $hours_1;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours2()
    {
        return $this->hours_2;
    }

    /**
     * @param float $hours_2
     *
     * @return $this
     */
    public function setHours2($hours_2): static
    {
        $this->hours_2 = $hours_2;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours3()
    {
        return $this->hours_3;
    }

    /**
     * @param float $hours_3
     *
     * @return $this
     */
    public function setHours3($hours_3): static
    {
        $this->hours_3 = $hours_3;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours4()
    {
        return $this->hours_4;
    }

    /**
     * @param float $hours_4
     *
     * @return $this
     */
    public function setHours4($hours_4): static
    {
        $this->hours_4 = $hours_4;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours5()
    {
        return $this->hours_5;
    }

    /**
     * @param float $hours_5
     *
     * @return $this
     */
    public function setHours5($hours_5): static
    {
        $this->hours_5 = $hours_5;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours6()
    {
        return $this->hours_6;
    }

    /**
     * @param float $hours_6
     *
     * @return $this
     */
    public function setHours6($hours_6): static
    {
        $this->hours_6 = $hours_6;

        return $this;
    }
}
