<?php
declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;

#[Map(target: \App\Entity\Activity::class)]
final class ActivitySaveDto
{
    public int $id = 0;

    #[Assert\NotBlank(message: 'Please provide a valid activity name with at least 3 letters.')]
    #[Assert\Length(min: 3, minMessage: 'Please provide a valid activity name with at least 3 letters.')]
    public string $name = '';

    public bool $needsTicket = false;

    #[Assert\GreaterThanOrEqual(value: 0, message: 'Factor must be greater than or equal to 0.')]
    public float $factor = 0.0;
}


