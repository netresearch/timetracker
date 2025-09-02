<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

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
        
        if ($dto instanceof \App\Dto\UserSaveDto) {
            $userId = $dto->id;
        }

        $repository = $this->entityManager->getRepository(User::class);
        $qb = $repository->createQueryBuilder('u')
            ->where('u.abbr = :abbr')
            ->setParameter('abbr', $value)
        ;

        if ($userId > 0) {
            $qb->andWhere('u.id != :id')
                ->setParameter('id', $userId)
            ;
        }

        $existingUser = $qb->getQuery()->getOneOrNullResult();

        if (null !== $existingUser) {
            $this->context->buildViolation($constraint->message)
                ->addViolation()
            ;
        }
    }
}
