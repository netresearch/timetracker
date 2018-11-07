<?php

namespace Netresearch\TimeTrackerBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Netresearch\TimeTrackerBundle\Model\Base as Base;

/**
 * Netresearch\TimeTrackerBundle\Entity\Holiday
 *
 * @ORM\Entity(repositoryClass="Netresearch\TimeTrackerBundle\Repository\HolidayRepository")
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
     * @var string $name
     */
    private $name;


    public function __construct($day, $name)
    {
        $this->setDay($day);
        $this->name = $name;
    }

    /**
     * Set day
     *
     * @param string $day
     *
     * @return $this
     */
    public function setDay($day)
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
    public function setName($name)
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
     *
     * @return array
     */
    public function toArray()
    {
        return array(
            'day'         => $this->getDay() ? $this->getDay()->format('d/m/Y') : null,
            'description' => $this->getName()
        );
    }

}
