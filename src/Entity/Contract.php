<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContractRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user contract (working hours).
 */
#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'contracts')]
class Contract
{
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'contracts')]
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
    protected DateTime $start;

    #[ORM\Column(type: 'date', nullable: true)]
    protected ?DateTime $end = null;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_0', type: 'float', nullable: false)]
    protected $hours0;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_1', type: 'float', nullable: false)]
    protected $hours1;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_2', type: 'float', nullable: false)]
    protected $hours2;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_3', type: 'float', nullable: false)]
    protected $hours3;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_4', type: 'float', nullable: false)]
    protected $hours4;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_5', type: 'float', nullable: false)]
    protected $hours5;

    /**
     * @var float
     */
    #[ORM\Column(name: 'hours_6', type: 'float', nullable: false)]
    protected $hours6;

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

    public function getUser(): User
    {
        return $this->user;
    }

    public function getStart(): DateTime
    {
        return $this->start;
    }

    /**
     * @return $this
     */
    public function setStart(DateTime $start): static
    {
        $this->start = $start;

        return $this;
    }

    public function getEnd(): ?DateTime
    {
        return $this->end;
    }

    /**
     * @return $this
     */
    public function setEnd(?DateTime $dateTimed): static
    {
        $this->end = $dateTimed;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours0()
    {
        return $this->hours0;
    }

    /**
     * @param float $hours_0
     *
     * @return $this
     */
    public function setHours0($hours_0): static
    {
        $this->hours0 = $hours_0;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours1()
    {
        return $this->hours1;
    }

    /**
     * @param float $hours_1
     *
     * @return $this
     */
    public function setHours1($hours_1): static
    {
        $this->hours1 = $hours_1;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours2()
    {
        return $this->hours2;
    }

    /**
     * @param float $hours_2
     *
     * @return $this
     */
    public function setHours2($hours_2): static
    {
        $this->hours2 = $hours_2;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours3()
    {
        return $this->hours3;
    }

    /**
     * @param float $hours_3
     *
     * @return $this
     */
    public function setHours3($hours_3): static
    {
        $this->hours3 = $hours_3;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours4()
    {
        return $this->hours4;
    }

    /**
     * @param float $hours_4
     *
     * @return $this
     */
    public function setHours4($hours_4): static
    {
        $this->hours4 = $hours_4;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours5()
    {
        return $this->hours5;
    }

    /**
     * @param float $hours_5
     *
     * @return $this
     */
    public function setHours5($hours_5): static
    {
        $this->hours5 = $hours_5;

        return $this;
    }

    /**
     * @return float
     */
    public function getHours6()
    {
        return $this->hours6;
    }

    /**
     * @param float $hours_6
     *
     * @return $this
     */
    public function setHours6($hours_6): static
    {
        $this->hours6 = $hours_6;

        return $this;
    }
}
