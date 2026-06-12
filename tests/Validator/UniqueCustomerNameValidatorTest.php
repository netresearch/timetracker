<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\CustomerSaveDto;
use App\Entity\Customer;
use App\Validator\Constraints\UniqueCustomerName;
use App\Validator\Constraints\UniqueCustomerNameValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Unit tests for UniqueCustomerNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueCustomerNameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueCustomerNameValidatorTest extends TestCase
{
    use UniquenessValidatorMockTrait;

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
        $this->mockRepositoryResult($this->entityManager, Customer::class, null);

        $dto = new CustomerSaveDto(id: 0, name: 'New Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New Customer', new UniqueCustomerName(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameCustomer(): void
    {
        // When updating the same customer, the andWhere clause excludes it,
        // so the query returns null (no other customer with that name)
        $this->mockRepositoryResult($this->entityManager, Customer::class, null);

        $dto = new CustomerSaveDto(id: 5, name: 'Existing Customer');
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('Existing Customer', new UniqueCustomerName(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFoundForNewCustomer(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, Customer::class, $existingCustomer);

        $dto = new CustomerSaveDto(id: 0, name: 'Duplicate Name');
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('Duplicate Name', new UniqueCustomerName(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentCustomerHasSameName(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, Customer::class, $existingCustomer);

        $dto = new CustomerSaveDto(id: 10, name: 'Conflicting Name');
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('Conflicting Name', new UniqueCustomerName(), $this->context);
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingCustomer = self::createStub(Customer::class);
        $existingCustomer->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, Customer::class, $existingCustomer);

        // Context object is not a CustomerSaveDto (simulating raw validation)
        $this->context->method('getObject')->willReturn(new stdClass());

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('Some Name', new UniqueCustomerName(), $this->context);
    }
}
