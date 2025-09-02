<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Repository\ActivityRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueActivityNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueActivityName) {
            throw new UnexpectedTypeException($constraint, UniqueActivityName::class);
        }

        // Early return for null or empty values
        if (null === $value || '' === $value) {
            return;
        }

        // Ensure value is string for repository query
        if (!is_string($value)) {
            return; // Let other validators handle non-string values
        }

        $existingActivity = $this->activityRepository->findOneBy(['name' => $value]);

        if (null !== $existingActivity) {
            // Check if we're updating an existing activity
            $object = $this->context->getObject();
            
            // Type-safe check for ActivitySaveDto
            if ($object instanceof \App\Dto\ActivitySaveDto && $object->id > 0) {
                if ($existingActivity->getId() === $object->id) {
                    return; // Same activity being updated
                }
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation()
            ;
        }
    }
}
