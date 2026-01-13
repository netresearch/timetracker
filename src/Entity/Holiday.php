<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\Base;
use BadMethodCallException;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Override;

/**
 * App\Entity\Holiday.
 */
#[ORM\Entity(repositoryClass: \App\Repository\HolidayRepository::class)]
#[ORM\Table(name: 'holidays')]
class Holiday extends Base
{
    #[ORM\Id]
    #[ORM\Column(name: 'day', type: 'date')]
    private readonly DateTime $day;

    public function __construct(string|DateTime $day, #[ORM\Column(name: 'name', type: 'string', length: 255)]
        private readonly string $name)
    {
        // Initialize properties immediately in constructor for PSALM compliance
        $this->day = $day instanceof DateTime ? $day : new DateTime($day);
    }

    public function setDay(string|DateTime $day): static
    {
        // For readonly properties, we cannot reassign after construction
        // This method exists for backward compatibility but should not be used
        if (!$day instanceof DateTime) {
            $day = new DateTime($day);
        }

        // Cannot modify readonly property after initialization
        throw new BadMethodCallException('Cannot modify readonly property $day after construction. Use constructor instead.');
    }

    /**
     * Get day.
     */
    public function getDay(): DateTime
    {
        return $this->day;
    }

    /**
     * Set name.
     */
    public function setName(string $name): void
    {
        // Cannot modify readonly property after initialization
        throw new BadMethodCallException('Cannot modify readonly property $name after construction. Use constructor instead.');
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
    #[Override]
    public function toArray(): array
    {
        return [
            'day' => $this->getDay()->format('d/m/Y'),
            'description' => $this->getName(),
        ];
    }
}
