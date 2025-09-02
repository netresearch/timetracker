<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueActivityName extends Constraint
{
    public string $message = 'The activity name "{{ value }}" already exists.';
}
