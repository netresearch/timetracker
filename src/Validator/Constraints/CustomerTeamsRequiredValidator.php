<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class CustomerTeamsRequiredValidator extends ConstraintValidator
{
    /**
     * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException When constraint type is invalid
     * @throws \Exception When validation context access fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CustomerTeamsRequired) {
            throw new UnexpectedTypeException($constraint, CustomerTeamsRequired::class);
        }

        if (!$value instanceof \App\Dto\CustomerSaveDto) {
            return;
        }

        // If customer is not global, teams must be provided
        $global = $value->global;
        $teams = $value->teams;

        if (!$global && empty($teams)) {
            $this->context->buildViolation($constraint->message)
                ->addViolation()
            ;
        }
    }
}
