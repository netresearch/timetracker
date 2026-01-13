<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Dto\CustomerSaveDto;
use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class UniqueCustomerNameValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @throws UnexpectedTypeException When constraint type is invalid
     * @throws Exception               When database operations fail
     * @throws Exception               When validation context or query execution fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueCustomerName) {
            throw new UnexpectedTypeException($constraint, UniqueCustomerName::class);
        }

        if (null === $value || '' === $value) {
            return;
        }

        // Get the current customer ID from the context object (the DTO)
        $dto = $this->context->getObject();
        $customerId = 0;

        if ($dto instanceof CustomerSaveDto) {
            $customerId = $dto->id;
        }

        $entityRepository = $this->entityManager->getRepository(Customer::class);
        $queryBuilder = $entityRepository->createQueryBuilder('c')
            ->where('c.name = :name')
            ->setParameter('name', $value);

        if ($customerId > 0) {
            $queryBuilder->andWhere('c.id != :id')
                ->setParameter('id', $customerId);
        }

        $existingCustomer = $queryBuilder->getQuery()->getOneOrNullResult();

        if (null !== $existingCustomer) {
            $this->context->buildViolation($constraint->message)
                ->addViolation();
        }
    }
}
