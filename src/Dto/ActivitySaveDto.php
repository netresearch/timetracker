<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Activity;
use App\Validator\Constraints\UniqueActivityName;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: Activity::class)]
final readonly class ActivitySaveDto
{
    public function __construct(
        public int $id = 0,
        #[Assert\NotBlank(message: 'Please provide a valid activity name with at least 3 letters.')]
        #[Assert\Length(min: 3, minMessage: 'Please provide a valid activity name with at least 3 letters.')]
        #[UniqueActivityName]
        public string $name = '',
        public bool $needsTicket = false,
        #[Assert\GreaterThanOrEqual(value: 0, message: 'Factor must be greater than or equal to 0.')]
        public float $factor = 0.0,
    ) {
    }
}
