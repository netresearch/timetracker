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

class UniqueUserAbbrValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueUserAbbr) {
            throw new UnexpectedTypeException($constraint, UniqueUserAbbr::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Get the current user ID from the context object (the DTO)
        $dto = $this->context->getObject();
        $userId = 0;

        if ($dto instanceof UserSaveDto) {
            $userId = $dto->id;
        }

        $entityRepository = $this->entityManager->getRepository(User::class);

        // Grandfather an unchanged abbreviation: editing an existing user (e.g.
        // just toggling "active" in a bulk action) must not fail because a
        // *different* legacy user already shares the abbreviation. Only a new or
        // actually-changed abbreviation is checked for uniqueness, so existing
        // duplicates coexist while no new collision can be introduced.
        if ($userId > 0) {
            $current = $entityRepository->find($userId);
            if ($current instanceof User && $current->getAbbr() === $value) {
                return;
            }
        }

        $queryBuilder = $entityRepository->createQueryBuilder('u')
            ->where('u.abbr = :abbr')
            ->setParameter('abbr', $value);

        if ($userId > 0) {
            $queryBuilder->andWhere('u.id != :id')
                ->setParameter('id', $userId);
        }

        $existingUser = $queryBuilder->getQuery()->getOneOrNullResult();

        if (null !== $existingUser) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
