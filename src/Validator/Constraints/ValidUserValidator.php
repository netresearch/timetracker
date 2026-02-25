<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidUserValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUser) {
            throw new UnexpectedTypeException($constraint, ValidUser::class);
        }

        if (null === $value || 0 === $value) {
            // Let other validators handle empty values
            return;
        }

        $user = $this->entityManager->getRepository(User::class)->find($value);
        if (null === $user) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
