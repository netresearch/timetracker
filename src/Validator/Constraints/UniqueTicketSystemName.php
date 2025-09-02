<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
class UniqueTicketSystemName extends Constraint
{
    public string $message = 'The ticket system name "{{ value }}" already exists.';
}
