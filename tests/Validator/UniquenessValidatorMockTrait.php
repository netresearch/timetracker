<?php

declare(strict_types=1);

namespace Tests\Validator;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Shared mock plumbing for the uniqueness-validator tests that query
 * through the EntityManager/QueryBuilder chain.
 */
trait UniquenessValidatorMockTrait
{
    /**
     * Mocks the repository chain so the uniqueness query returns $result.
     *
     * @param class-string $entityClass
     */
    private function mockRepositoryResult(
        EntityManagerInterface&MockObject $entityManager,
        string $entityClass,
        ?object $result,
    ): void {
        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $entityManager->expects(self::once())->method('getRepository')
            ->with($entityClass)
            ->willReturn($repository);
    }

    private function expectSingleViolation(ExecutionContextInterface&MockObject $context): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $context->method('buildViolation')
            ->willReturn($violationBuilder);
    }
}
