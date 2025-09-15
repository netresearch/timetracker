<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
class CustomerTeamsRequired extends Constraint
{
    public string $message = 'Teams must be specified when customer is not global.';

    #[Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
