<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueUsernameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (! $constraint instanceof UniqueUsername) {
            throw new UnexpectedTypeException($constraint, UniqueUsername::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Get the current user ID from the context object (the DTO)
        $dto = $this->context->getObject();
        $userId = 0;

        if ($dto instanceof \App\Dto\UserSaveDto) {
            $userId = $dto->id;
        }

        $entityRepository = $this->entityManager->getRepository(User::class);
        $queryBuilder = $entityRepository->createQueryBuilder('u')
            ->where('u.username = :username')
            ->setParameter('username', $value);

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
