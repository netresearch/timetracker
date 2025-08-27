<?php
declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use Doctrine\ORM\Mapping as ORM;

/**
 * App\Entity\Holiday.
 */
#[ORM\Entity(repositoryClass: \App\Repository\HolidayRepository::class)]
#[ORM\Table(name: 'holidays')]
class Holiday extends Base
{
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: 'date')]
    private \DateTime $day;

    #[ORM\Column(name: 'name', type: 'string', length: 255)]
    private string $name;

    public function __construct(string|\DateTime $day, string $name)
    {
        $this->setDay($day);
        $this->setName($name);
    }

    public function setDay(string|\DateTime $day): static
    {
        if (!$day instanceof \DateTime) {
            $day = new \DateTime($day);
        }

        $this->day = $day;

        return $this;
    }

    /**
     * Get day.
     */
    public function getDay(): ?\DateTime
    {
        return $this->day;
    }

    /**
     * Set name.
     */
    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * Get name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get array representation of holiday object.
     *
     * @return (string|null)[]
     *
     * @psalm-return array{day: null|string, description: string}
     */
    public function toArray(): array
    {
        return [
            'day' => $this->getDay() instanceof \DateTime ? $this->getDay()->format('d/m/Y') : null,
            'description' => $this->getName(),
        ];
    }
}
