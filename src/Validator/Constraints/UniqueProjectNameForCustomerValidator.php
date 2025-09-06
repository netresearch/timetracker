<?php

declare(strict_types=1);

namespace App\Validator\Constraints;

use App\Repository\ProjectRepository;
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
     * @throws \Symfony\Component\Validator\Exception\UnexpectedTypeException When constraint type is invalid
     * @throws \Doctrine\ORM\ORMException When database operations fail
     * @throws \Exception When validation context or repository access fails
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueProjectNameForCustomer) {
            throw new UnexpectedTypeException($constraint, UniqueProjectNameForCustomer::class);
        }

        if (!$value instanceof \App\Dto\ProjectSaveDto) {
            return;
        }

        // Get the project name and customer ID from the DTO
        $name = $value->name;
        $customerId = $value->customer;
        $projectId = $value->id;

        if (empty($name) || null === $customerId) {
            return; // Other validators will handle these
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
                ->addViolation()
            ;
        }
    }
}
