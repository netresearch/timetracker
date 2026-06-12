<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\TicketSystemSaveDto;
use App\Entity\TicketSystem;
use App\Repository\TicketSystemRepository;
use App\Validator\Constraints\UniqueTicketSystemName;
use App\Validator\Constraints\UniqueTicketSystemNameValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueTicketSystemNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueTicketSystemNameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueTicketSystemNameValidatorTest extends TestCase
{
    private TicketSystemRepository&MockObject $repository;
    private ExecutionContextInterface&MockObject $context;
    private UniqueTicketSystemNameValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TicketSystemRepository::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueTicketSystemNameValidator($this->repository);
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validateInContext('test', $constraint, $this->context);
    }

    public function testValidateReturnsEarlyForNullValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(null, new UniqueTicketSystemName(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext('', new UniqueTicketSystemName(), $this->context);
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(123, new UniqueTicketSystemName(), $this->context);
    }

    public function testValidatePassesWhenNoExistingSystemFound(): void
    {
        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'New System'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New System', new UniqueTicketSystemName(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameSystem(): void
    {
        $this->mockExistingSystemFound('Existing System');

        $dto = new TicketSystemSaveDto(id: 5, name: 'Existing System');

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('Existing System', new UniqueTicketSystemName(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $this->mockExistingSystemFound('Duplicate Name');

        $dto = new TicketSystemSaveDto(id: 0, name: 'Duplicate Name');

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Duplicate Name', new UniqueTicketSystemName(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentSystemHasSameName(): void
    {
        $this->mockExistingSystemFound('Conflicting Name');

        $dto = new TicketSystemSaveDto(id: 10, name: 'Conflicting Name');

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Conflicting Name', new UniqueTicketSystemName(), $this->context);
    }

    /**
     * Mocks the repository so the uniqueness lookup finds an existing system (id 5) with $name.
     */
    private function mockExistingSystemFound(string $name): void
    {
        $existingSystem = self::createStub(TicketSystem::class);
        $existingSystem->method('getId')->willReturn(5);

        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => $name])
            ->willReturn($existingSystem);
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);
    }
}
