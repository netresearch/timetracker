<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use DateTime;
use Exception;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ContractDatesValidValidator extends ConstraintValidator
{
    /**
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When date parsing or validation context fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof ContractDatesValid) {
            throw new UnexpectedTypeException($constraint, ContractDatesValid::class);
        }

        if (! $value instanceof \App\Dto\ContractSaveDto) {
            return;
        }

        $start = $value->start;
        $end = $value->end;

        if ('' === $start || '0' === $start || null === $end || '' === $end) {
            return; // Other validators will handle empty values
        }

        $dateStart = DateTime::createFromFormat('Y-m-d', $start);
        $dateEnd = DateTime::createFromFormat('Y-m-d', $end);

        if (false === $dateStart || false === $dateEnd) {
            return; // Invalid date format, let other validators handle it
        }

        if ($dateEnd < $dateStart) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
