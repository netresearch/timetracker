<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
class UniqueProjectNameForCustomer extends Constraint
{
    public string $message = 'A project with the name "{{ value }}" already exists for this customer.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
