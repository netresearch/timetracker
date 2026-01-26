<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\ProjectSaveDto;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Validator\Constraints\UniqueProjectNameForCustomer;
use App\Validator\Constraints\UniqueProjectNameForCustomerValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueProjectNameForCustomerValidator.
 *
 * @internal
 */
#[CoversClass(UniqueProjectNameForCustomerValidator::class)]
final class UniqueProjectNameForCustomerValidatorTest extends TestCase
{
    private ProjectRepository&MockObject $repository;
    private ExecutionContextInterface&MockObject $context;
    private UniqueProjectNameForCustomerValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ProjectRepository::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueProjectNameForCustomerValidator($this->repository);
        $this->validator->initialize($this->context);
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = $this->createMock(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $dto = new ProjectSaveDto(name: 'Test', customer: 1);
        $this->validator->validate($dto, $constraint);
    }

    public function testValidateReturnsEarlyForNonDtoValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate('not-a-dto', new UniqueProjectNameForCustomer());
    }

    public function testValidateReturnsEarlyForEmptyName(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: '', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidateReturnsEarlyForZeroStringName(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: '0', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidateReturnsEarlyForNullCustomer(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: 'Project', customer: null);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidatePassesWhenNoExistingProjectFound(): void
    {
        $this->repository->method('findOneBy')
            ->with(['name' => 'New Project', 'customer' => 1])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $dto = new ProjectSaveDto(name: 'New Project', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidatePassesWhenUpdatingSameProject(): void
    {
        $existingProject = $this->createMock(Project::class);
        $existingProject->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Existing Project', 'customer' => 1])
            ->willReturn($existingProject);

        $this->context->expects(self::never())->method('buildViolation');

        $dto = new ProjectSaveDto(id: 5, name: 'Existing Project', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $existingProject = $this->createMock(Project::class);
        $existingProject->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Duplicate Name', 'customer' => 1])
            ->willReturn($existingProject);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $dto = new ProjectSaveDto(id: 0, name: 'Duplicate Name', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }

    public function testValidateAddsViolationWhenDifferentProjectHasSameName(): void
    {
        $existingProject = $this->createMock(Project::class);
        $existingProject->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Conflicting Name', 'customer' => 1])
            ->willReturn($existingProject);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $dto = new ProjectSaveDto(id: 10, name: 'Conflicting Name', customer: 1);
        $this->validator->validate($dto, new UniqueProjectNameForCustomer());
    }
}
