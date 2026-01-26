<?php

declare(strict_types=1);

namespace Tests\Validator\Constraints;

use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Validator\Constraints\UniqueTeamName;
use App\Validator\Constraints\UniqueTeamNameValidator;
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
        $this->validator->initialize($this->context);
    }

    // ==================== Constraint type tests ====================

    public function testValidateThrowsOnWrongConstraintType(): void
    {
        $constraint = $this->createMock(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('Team Name', $constraint);
    }

    // ==================== Empty/null value tests ====================

    public function testValidateSkipsNullValue(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate(null, new UniqueTeamName());
    }

    public function testValidateSkipsEmptyString(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate('', new UniqueTeamName());
    }

    public function testValidateSkipsNonStringValue(): void
    {
        $this->teamRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate(123, new UniqueTeamName());
    }

    // ==================== Unique name tests ====================

    public function testValidatePassesWhenNameIsUnique(): void
    {
        $this->teamRepository->method('findOneBy')
            ->with(['name' => 'New Team'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('New Team', new UniqueTeamName());
    }

    // ==================== Duplicate name tests ====================

    public function testValidateFailsWhenNameExists(): void
    {
        $existingTeam = new Team();

        $this->teamRepository->method('findOneBy')
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

        $this->validator->validate('Existing Team', new UniqueTeamName());
    }

    // ==================== Update existing team tests ====================

    public function testValidatePassesWhenUpdatingSameTeam(): void
    {
        $existingTeam = $this->createTeamWithId(42);

        $this->teamRepository->method('findOneBy')
            ->with(['name' => 'My Team'])
            ->willReturn($existingTeam);

        // TeamSaveDto is readonly, constructor is: id, name, lead_user_id
        $dto = new TeamSaveDto(id: 42, name: 'My Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('My Team', new UniqueTeamName());
    }

    public function testValidateFailsWhenUpdatingToDifferentExistingName(): void
    {
        $existingTeam = $this->createTeamWithId(42);

        $this->teamRepository->method('findOneBy')
            ->with(['name' => 'Other Team'])
            ->willReturn($existingTeam);

        // Different ID than the existing team
        $dto = new TeamSaveDto(id: 99, name: 'Other Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Other Team', new UniqueTeamName());
    }

    public function testValidateFailsWhenCreatingNewWithExistingName(): void
    {
        $existingTeam = $this->createTeamWithId(42);

        $this->teamRepository->method('findOneBy')
            ->with(['name' => 'Taken Name'])
            ->willReturn($existingTeam);

        // New team (id = 0)
        $dto = new TeamSaveDto(id: 0, name: 'Taken Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturn($violationBuilder);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Taken Name', new UniqueTeamName());
    }

    // ==================== Helper methods ====================

    private function createTeamWithId(int $id): Team
    {
        $team = new Team();
        $reflection = new ReflectionProperty(Team::class, 'id');
        $reflection->setValue($team, $id);

        return $team;
    }
}
