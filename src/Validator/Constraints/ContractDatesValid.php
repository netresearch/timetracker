<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
class ContractDatesValid extends Constraint
{
    public string $message = 'End date has to be greater than the start date.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
