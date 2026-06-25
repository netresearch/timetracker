<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\ProjectSaveDto;
use App\Entity\Customer;
use App\Entity\Project;
use App\Repository\ProjectRepository;
use App\Validator\Constraints\UniqueProjectNameForCustomer;
use App\Validator\Constraints\UniqueProjectNameForCustomerValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
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
#[AllowMockObjectsWithoutExpectations]
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
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $dto = new ProjectSaveDto(name: 'Test', customer: 1);
        $this->validator->validateInContext($dto, $constraint, $this->context);
    }

    public function testValidateReturnsEarlyForNonDtoValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext('not-a-dto', new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyName(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: '', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateReturnsEarlyForZeroStringName(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: '0', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateReturnsEarlyForNullCustomer(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $dto = new ProjectSaveDto(name: 'Project', customer: null);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidatePassesWhenNoExistingProjectFound(): void
    {
        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'New Project', 'customer' => 1])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $dto = new ProjectSaveDto(name: 'New Project', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameProject(): void
    {
        $this->mockExistingProjectFound('Existing Project');

        $this->context->expects(self::never())->method('buildViolation');

        $dto = new ProjectSaveDto(id: 5, name: 'Existing Project', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $this->mockExistingProjectFound('Duplicate Name');

        $this->expectSingleViolation();

        $dto = new ProjectSaveDto(id: 0, name: 'Duplicate Name', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentProjectHasSameName(): void
    {
        $this->mockExistingProjectFound('Conflicting Name');

        $this->expectSingleViolation();

        $dto = new ProjectSaveDto(id: 10, name: 'Conflicting Name', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateGrandfathersAnUnchangedNameDespiteALegacyDuplicate(): void
    {
        // Re-saving project 5 with its UNCHANGED name+customer (e.g. just toggling
        // active) must pass even if a different project shares the name — the
        // uniqueness lookup is skipped entirely.
        $this->repository->expects(self::once())->method('find')->with(5)
            ->willReturn($this->stubProject(5, 'Existing Project', 1));
        $this->repository->expects(self::never())->method('findOneBy');
        $this->context->expects(self::never())->method('buildViolation');

        $dto = new ProjectSaveDto(id: 5, name: 'Existing Project', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    public function testValidateStillRejectsChangingToACollidingName(): void
    {
        // Changing project 5's name to one another project (id 8) already holds is
        // still rejected — the grandfather only covers an unchanged name.
        $this->repository->expects(self::once())->method('find')->with(5)
            ->willReturn($this->stubProject(5, 'Old Name', 1));
        $other = self::createStub(Project::class);
        $other->method('getId')->willReturn(8);
        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'Taken Name', 'customer' => 1])
            ->willReturn($other);
        $this->expectSingleViolation();

        $dto = new ProjectSaveDto(id: 5, name: 'Taken Name', customer: 1);
        $this->validator->validateInContext($dto, new UniqueProjectNameForCustomer(), $this->context);
    }

    private function stubProject(int $id, string $name, int $customerId): Project
    {
        $customer = self::createStub(Customer::class);
        $customer->method('getId')->willReturn($customerId);
        $project = self::createStub(Project::class);
        $project->method('getId')->willReturn($id);
        $project->method('getName')->willReturn($name);
        $project->method('getCustomer')->willReturn($customer);

        return $project;
    }

    /**
     * Mocks the repository so the uniqueness lookup finds an existing project (id 5) with $name for customer 1.
     */
    private function mockExistingProjectFound(string $name): void
    {
        $existingProject = self::createStub(Project::class);
        $existingProject->method('getId')->willReturn(5);

        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => $name, 'customer' => 1])
            ->willReturn($existingProject);
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
