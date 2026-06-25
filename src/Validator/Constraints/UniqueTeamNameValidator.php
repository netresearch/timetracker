<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Repository\TeamRepository;
use Exception;
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
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When database operations fail
     * @throws Exception               When validation context or repository access fails
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

        $object = $this->context->getObject();

        // Grandfather an unchanged name: re-saving an existing team (e.g. just
        // toggling a flag) must not fail because a legacy duplicate shares the
        // name. Only a new or changed name is checked.
        if ($object instanceof TeamSaveDto && $object->id > 0) {
            $current = $this->teamRepository->find($object->id);
            if ($current instanceof Team && $current->getName() === $value) {
                return;
            }
        }

        $existingTeam = $this->teamRepository->findOneBy(['name' => $value]);

        if (null !== $existingTeam) {
            // Type-safe check for TeamSaveDto
            if ($object instanceof TeamSaveDto && $object->id > 0 && $existingTeam->getId() === $object->id) {
                return;
                // Same team being updated
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
