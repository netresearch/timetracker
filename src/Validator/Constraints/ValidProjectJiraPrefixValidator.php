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
use function preg_match;
use function trim;

class ValidProjectJiraPrefixValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidProjectJiraPrefix) {
            throw new UnexpectedTypeException($constraint, ValidProjectJiraPrefix::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        if (!is_string($value)) {
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }

        $dto = $this->context->getObject();
        $projectId = $dto instanceof ProjectSaveDto ? $dto->id : 0;

        // Grandfather an unchanged prefix: re-saving an existing project — to give
        // it a ticket system, to deactivate it — must not be blocked because its
        // prefix predates the format rule. Cast the persisted value so a NULL
        // legacy prefix equals a submitted ''.
        if ($projectId > 0) {
            $current = $this->entityManager->getRepository(Project::class)->find($projectId);
            if ($current instanceof Project && trim((string) $current->getJiraId()) === trim($value)) {
                return;
            }
        }

        if (1 !== preg_match('/^[A-Z]+$/', trim($value))) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
