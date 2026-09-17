<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\ProjectSaveDto;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_string;
use function mb_strlen;
use function trim;

class ValidProjectNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProjectName) {
            throw new UnexpectedTypeException($constraint, ValidProjectName::class);
        }

        $dto = $this->context->getObject();
        $projectId = $dto instanceof ProjectSaveDto ? $dto->id : 0;

        // Grandfather an unchanged name, for the reason ValidProjectJiraPrefix
        // states: an existing project with a two-character legacy name must stay
        // editable.
        if ($projectId > 0 && is_string($value)) {
            $current = $this->entityManager->getRepository(Project::class)->find($projectId);
            if ($current instanceof Project && $current->getName() === $value) {
                return;
            }
        }

        if (!is_string($value) || mb_strlen(trim($value)) < 3) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
