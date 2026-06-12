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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
#[AllowMockObjectsWithoutExpectations]
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
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validateInContext('test', $constraint, $this->context);
    }

    public function testValidateReturnsEarlyForNullValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validateInContext(null, new UniqueCustomerName(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validateInContext('', new UniqueCustomerName(), $this->context);
    }

    public function testValidatePassesWhenNoExistingCustomerFound(): void
    {
        $this->mockRepositoryResult(null);

        $dto = new CustomerSaveDto(id: 0, name: 'New Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New Customer', new UniqueCustomerName(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameCustomer(): void
    {
        // When updating the same customer, the andWhere clause excludes it,
        // so the query returns null (no other customer with that name)
        $this->mockRepositoryResult(null);

        $dto = new CustomerSaveDto(id: 5, name: 'Existing Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('Existing Customer', new UniqueCustomerName(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFoundForNewCustomer(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingCustomer);

        $dto = new CustomerSaveDto(id: 0, name: 'Duplicate Name');
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Duplicate Name', new UniqueCustomerName(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentCustomerHasSameName(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingCustomer);

        $dto = new CustomerSaveDto(id: 10, name: 'Conflicting Name');
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Conflicting Name', new UniqueCustomerName(), $this->context);
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingCustomer);

        // Context object is not a CustomerSaveDto (simulating raw validation)
        $this->context->method('getObject')->willReturn(new stdClass());

        $this->expectSingleViolation();

        $this->validator->validateInContext('Some Name', new UniqueCustomerName(), $this->context);
    }

    /**
     * Mocks the repository chain so the uniqueness query returns $result.
     */
    private function mockRepositoryResult(?object $result): void
    {
        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(Customer::class)
            ->willReturn($repository);
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);
    }
}
