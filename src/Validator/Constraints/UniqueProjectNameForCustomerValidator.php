<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\ProjectSaveDto;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use Exception;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueProjectNameForCustomerValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ProjectRepository $projectRepository,
    ) {
    }

    /**
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When database operations fail
     * @throws Exception               When validation context or repository access fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueProjectNameForCustomer) {
            throw new UnexpectedTypeException($constraint, UniqueProjectNameForCustomer::class);
        }

        if (!$value instanceof ProjectSaveDto) {
            return;
        }

        // Get the project name and customer ID from the DTO
        $name = $value->name;
        $customerId = $value->customer;
        $projectId = $value->id;

        if ('' === $name || '0' === $name || null === $customerId) {
            return; // Other validators will handle these
        }

        // Grandfather an unchanged name+customer: re-saving an existing project
        // (e.g. just toggling "active") must not fail because a legacy duplicate
        // shares the name for this customer. Only a new or changed name/customer
        // is checked, so existing duplicates coexist while no new one is created.
        if ($projectId > 0) {
            $current = $this->projectRepository->find($projectId);
            if ($current instanceof Project && $current->getName() === $name && $current->getCustomer()?->getId() === $customerId) {
                return;
            }
        }

        // Check if a project with this name already exists for this customer
        $existingProject = $this->projectRepository->findOneBy([
            'name' => $name,
            'customer' => $customerId,
        ]);

        if (null !== $existingProject) {
            // Check if we're updating an existing project
            if ($projectId > 0 && $existingProject->getId() === $projectId) {
                return; // Same project being updated
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $name)
                ->addViolation();
        }
    }
}
