<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;

/**
 * App\Entity\Holiday
 *
 * @ORM\Entity(repositoryClass="App\Repository\HolidayRepository")
 * @ORM\Table(name="holidays")
 */
class Holiday extends Base
{
    /**
     * @ORM\Id
     * @ORM\Column(type="date")
     */
    private $day;


    /**
     * @param string $name
     */
    public function __construct($day, private $name)
    {
        $this->setDay($day);
    }

    /**
     * Set day
     *
     * @param string $day
     *
     * @return $this
     */
    public function setDay($day): static
    {
        if (!$day instanceof \DateTime) {
            $day = new \DateTime($day);
        }

        $this->day = $day;
        return $this;
    }

    /**
     * Get day
     *
     * @return \DateTime
     */
    public function getDay()
    {
        return $this->day;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name): void
    {
        $this->name = $name;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get array representation of holiday object
     */
    public function toArray(): array
    {
        return [
            'day'         => $this->getDay() ? $this->getDay()->format('d/m/Y') : null,
            'description' => $this->getName()
        ];
    }

}
