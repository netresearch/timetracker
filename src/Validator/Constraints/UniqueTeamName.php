<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueTeamName extends Constraint
{
    public string $message = 'The team name "{{ value }}" already exists.';
}
