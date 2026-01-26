<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\CustomerSaveDto;
use App\Entity\Customer;
use App\Validator\Constraints\UniqueCustomerName;
use App\Validator\Constraints\UniqueCustomerNameValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueCustomerNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueCustomerNameValidator::class)]
final class UniqueCustomerNameValidatorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ExecutionContextInterface&MockObject $context;
    private UniqueCustomerNameValidator $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueCustomerNameValidator($this->entityManager);
        $this->validator->initialize($this->context);
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = $this->createMock(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('test', $constraint);
    }

    public function testValidateReturnsEarlyForNullValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate(null, new UniqueCustomerName());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate('', new UniqueCustomerName());
    }

    public function testValidatePassesWhenNoExistingCustomerFound(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);

        $dto = new CustomerSaveDto(id: 0, name: 'New Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('New Customer', new UniqueCustomerName());
    }

    public function testValidatePassesWhenUpdatingSameCustomer(): void
    {
        // When updating the same customer, the andWhere clause excludes it,
        // so the query returns null (no other customer with that name)
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);

        $dto = new CustomerSaveDto(id: 5, name: 'Existing Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('Existing Customer', new UniqueCustomerName());
    }

    public function testValidateAddsViolationWhenDuplicateNameFoundForNewCustomer(): void
    {
        $existingCustomer = $this->createMock(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingCustomer);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);

        $dto = new CustomerSaveDto(id: 0, name: 'Duplicate Name');
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Duplicate Name', new UniqueCustomerName());
    }

    public function testValidateAddsViolationWhenDifferentCustomerHasSameName(): void
    {
        $existingCustomer = $this->createMock(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingCustomer);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);

        $dto = new CustomerSaveDto(id: 10, name: 'Conflicting Name');
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Conflicting Name', new UniqueCustomerName());
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingCustomer = $this->createMock(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingCustomer);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);

        // Context object is not a CustomerSaveDto (simulating raw validation)
        $this->context->method('getObject')->willReturn(new stdClass());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Some Name', new UniqueCustomerName());
    }
}
