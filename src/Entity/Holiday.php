<?php declare(strict_types=1);

namespace App\Entity;

use App\Repository\HolidayRepository;
use Doctrine\DBAL\Types\Types;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use App\Model\Base;

/**
 * App\Entity\Holiday.
 */
#[ORM\Entity(repositoryClass: HolidayRepository::class)]
#[ORM\Table(name: 'holidays')]
class Holiday extends Base
{
    #[ORM\Id]
    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private $day;

    public function __construct($day, private $name)
    {
        $this->setDay($day);
    }

    /**
     * Set day.
     *
     * @param string $day
     *
     * @return $this
     */
    public function setDay(string $day)
    {
        if (!$day instanceof DateTime) {
            $day = new DateTime($day);
        }

        $this->day = $day;

        return $this;
    }

    /**
     * Get day.
     *
     * @return DateTime
     */
    public function getDay(): DateTime
    {
        return $this->day;
    }

    /**
     * Set name.
     *
     * @param string $name
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get array representation of holiday object.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'day'         => $this->getDay() ? $this->getDay()->format('d/m/Y') : null,
            'description' => $this->getName(),
        ];
    }
}
