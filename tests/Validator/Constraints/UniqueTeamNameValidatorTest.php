<?php

declare(strict_types=1);

namespace Tests\Validator\Constraints;

use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Validator\Constraints\UniqueTeamName;
use App\Validator\Constraints\UniqueTeamNameValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueTeamNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueTeamNameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueTeamNameValidatorTest extends TestCase
{
    private TeamRepository&MockObject $teamRepository;
    private ExecutionContextInterface&MockObject $context;
    private UniqueTeamNameValidator $validator;

    protected function setUp(): void
    {
        $this->teamRepository = $this->createMock(TeamRepository::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueTeamNameValidator($this->teamRepository);
    }

    // ==================== Constraint type tests ====================

    public function testValidateThrowsOnWrongConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validateInContext('Team Name', $constraint, $this->context);
    }

    // ==================== Empty/null value tests ====================

    public function testValidateSkipsNullValue(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(null, new UniqueTeamName(), $this->context);
    }

    public function testValidateSkipsEmptyString(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext('', new UniqueTeamName(), $this->context);
    }

    public function testValidateSkipsNonStringValue(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(123, new UniqueTeamName(), $this->context);
    }

    // ==================== Unique name tests ====================

    public function testValidatePassesWhenNameIsUnique(): void
    {
        $this->teamRepository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'New Team'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New Team', new UniqueTeamName(), $this->context);
    }

    // ==================== Duplicate name tests ====================

    public function testValidateFailsWhenNameExists(): void
    {
        $existingTeam = new Team();

        $this->teamRepository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'Existing Team'])
            ->willReturn($existingTeam);

        $this->context->method('getObject')->willReturn(null);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->with('The team name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);

        $this->validator->validateInContext('Existing Team', new UniqueTeamName(), $this->context);
    }

    // ==================== Update existing team tests ====================

    public function testValidatePassesWhenUpdatingSameTeam(): void
    {
        $this->mockExistingTeamFound('My Team');

        // TeamSaveDto is readonly, constructor is: id, name, lead_user_id
        $dto = new TeamSaveDto(id: 42, name: 'My Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('My Team', new UniqueTeamName(), $this->context);
    }

    public function testValidateFailsWhenUpdatingToDifferentExistingName(): void
    {
        $this->mockExistingTeamFound('Other Team');

        // Different ID than the existing team
        $dto = new TeamSaveDto(id: 99, name: 'Other Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Other Team', new UniqueTeamName(), $this->context);
    }

    public function testValidateFailsWhenCreatingNewWithExistingName(): void
    {
        $this->mockExistingTeamFound('Taken Name');

        // New team (id = 0)
        $dto = new TeamSaveDto(id: 0, name: 'Taken Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Taken Name', new UniqueTeamName(), $this->context);
    }

    // ==================== Helper methods ====================

    private function createTeamWithId(int $id): Team
    {
        $team = new Team();
        $reflection = new ReflectionProperty(Team::class, 'id');
        $reflection->setValue($team, $id);

        return $team;
    }

    /**
     * Mocks the repository so the uniqueness lookup finds an existing team (id 42) with $name.
     */
    private function mockExistingTeamFound(string $name): void
    {
        $existingTeam = $this->createTeamWithId(42);

        $this->teamRepository->expects(self::once())->method('findOneBy')
            ->with(['name' => $name])
            ->willReturn($existingTeam);
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);
    }
}
