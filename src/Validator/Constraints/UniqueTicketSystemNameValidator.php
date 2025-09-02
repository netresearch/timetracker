<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Repository\TicketSystemRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueTicketSystemNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TicketSystemRepository $ticketSystemRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueTicketSystemName) {
            throw new UnexpectedTypeException($constraint, UniqueTicketSystemName::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Ensure value is string for repository query
        if (!is_string($value)) {
            return; // Let other validators handle non-string values
        }

        $existingSystem = $this->ticketSystemRepository->findOneBy(['name' => $value]);

        if (null !== $existingSystem) {
            // Check if we're updating an existing ticket system
            $object = $this->context->getObject();
            
            // Type-safe check for TicketSystemSaveDto
            if ($object instanceof \App\Dto\TicketSystemSaveDto && $object->id > 0) {
                if ($existingSystem->getId() === $object->id) {
                    return; // Same ticket system being updated
                }
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation()
            ;
        }
    }
}
