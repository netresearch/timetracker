<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\TicketSystemSaveDto;
use App\Entity\TicketSystem;
use App\Repository\TicketSystemRepository;
use App\Validator\Constraints\UniqueTicketSystemName;
use App\Validator\Constraints\UniqueTicketSystemNameValidator;
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
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate(null, new UniqueTicketSystemName());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate('', new UniqueTicketSystemName());
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate(123, new UniqueTicketSystemName());
    }

    public function testValidatePassesWhenNoExistingSystemFound(): void
    {
        $this->repository->method('findOneBy')
            ->with(['name' => 'New System'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('New System', new UniqueTicketSystemName());
    }

    public function testValidatePassesWhenUpdatingSameSystem(): void
    {
        $existingSystem = $this->createMock(TicketSystem::class);
        $existingSystem->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Existing System'])
            ->willReturn($existingSystem);

        $dto = new TicketSystemSaveDto(id: 5, name: 'Existing System');

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('Existing System', new UniqueTicketSystemName());
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $existingSystem = $this->createMock(TicketSystem::class);
        $existingSystem->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Duplicate Name'])
            ->willReturn($existingSystem);

        $dto = new TicketSystemSaveDto(id: 0, name: 'Duplicate Name');

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Duplicate Name', new UniqueTicketSystemName());
    }

    public function testValidateAddsViolationWhenDifferentSystemHasSameName(): void
    {
        $existingSystem = $this->createMock(TicketSystem::class);
        $existingSystem->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Conflicting Name'])
            ->willReturn($existingSystem);

        $dto = new TicketSystemSaveDto(id: 10, name: 'Conflicting Name');

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Conflicting Name', new UniqueTicketSystemName());
    }
}
