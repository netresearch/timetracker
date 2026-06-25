<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\ActivitySaveDto;
use App\Entity\Activity;
use App\Repository\ActivityRepository;
use Exception;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_string;

class UniqueActivityNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ActivityRepository $activityRepository,
    ) {
    }

    /**
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When database operations fail
     * @throws Exception               When validation context or object access fails
     */
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

        $object = $this->context->getObject();

        // Grandfather an unchanged name: re-saving an existing activity (e.g. just
        // toggling a flag) must not fail because a legacy duplicate shares the
        // name. Only a new or changed name is checked.
        if ($object instanceof ActivitySaveDto && $object->id > 0) {
            $current = $this->activityRepository->find($object->id);
            if ($current instanceof Activity && $current->getName() === $value) {
                return;
            }
        }

        $existingActivity = $this->activityRepository->findOneBy(['name' => $value]);

        if (null !== $existingActivity) {
            // Type-safe check for ActivitySaveDto
            if ($object instanceof ActivitySaveDto && $object->id > 0 && $existingActivity->getId() === $object->id) {
                return;
                // Same activity being updated
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
