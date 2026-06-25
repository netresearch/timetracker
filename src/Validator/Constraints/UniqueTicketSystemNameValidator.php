<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\TicketSystemSaveDto;
use App\Entity\TicketSystem;
use App\Repository\TicketSystemRepository;
use Exception;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_string;

class UniqueTicketSystemNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TicketSystemRepository $ticketSystemRepository,
    ) {
    }

    /**
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When database operations fail
     * @throws Exception               When validation context or repository access fails
     */
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

        $object = $this->context->getObject();
        // TicketSystemSaveDto::$id is nullable, so narrow it explicitly before comparing.
        $id = $object instanceof TicketSystemSaveDto ? $object->id : null;

        // Grandfather an unchanged name: re-saving an existing ticket system (e.g.
        // just toggling a flag) must not fail because a legacy duplicate shares the
        // name. Only a new or changed name is checked.
        if (null !== $id && $id > 0) {
            $current = $this->ticketSystemRepository->find($id);
            if ($current instanceof TicketSystem && $current->getName() === $value) {
                return;
            }
        }

        $existingSystem = $this->ticketSystemRepository->findOneBy(['name' => $value]);

        if (null !== $existingSystem) {
            if (null !== $id && $id > 0 && $existingSystem->getId() === $id) {
                return;
                // Same ticket system being updated
            }

            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
