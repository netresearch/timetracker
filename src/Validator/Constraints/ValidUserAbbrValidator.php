<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\UserSaveDto;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function is_string;
use function mb_strlen;

class ValidUserAbbrValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidUserAbbr) {
            throw new UnexpectedTypeException($constraint, ValidUserAbbr::class);
        }

        $dto = $this->context->getObject();
        $userId = $dto instanceof UserSaveDto ? $dto->id : 0;

        // Grandfather an unchanged abbreviation: re-saving an existing user (e.g.
        // just toggling "active") must not be blocked because a legacy account has
        // an empty or over-long abbr. Only a new or changed abbr is length-checked.
        // Cast the persisted value so a NULL legacy abbr equals a submitted ''.
        if ($userId > 0) {
            $current = $this->entityManager->getRepository(User::class)->find($userId);
            if ($current instanceof User && (string) $current->getAbbr() === $value) {
                return;
            }
        }

        if (!is_string($value) || '' === $value || mb_strlen($value) > 3) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
