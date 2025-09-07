<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Repository\TeamRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_string;

class UniqueTeamNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TeamRepository $teamRepository,
    ) {
    }

    /**
     * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException When constraint type is invalid
     * @throws \Exception When database operations fail
     * @throws \Exception When validation context or repository access fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueTeamName) {
            throw new UnexpectedTypeException($constraint, UniqueTeamName::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Ensure value is string for repository query
        if (!is_string($value)) {
            return; // Let other validators handle non-string values
        }

        $existingTeam = $this->teamRepository->findOneBy(['name' => $value]);

        if (null !== $existingTeam) {
            // Check if we're updating an existing team
            $object = $this->context->getObject();

            // Type-safe check for TeamSaveDto
            if ($object instanceof \App\Dto\TeamSaveDto && $object->id > 0) {
                if ($existingTeam->getId() === $object->id) {
                    return; // Same team being updated
                }
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation()
            ;
        }
    }
}
