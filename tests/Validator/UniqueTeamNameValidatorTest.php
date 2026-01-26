<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Validator\Constraints\UniqueTeamName;
use App\Validator\Constraints\UniqueTeamNameValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
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
    private TeamRepository&MockObject $repository;
    private ExecutionContextInterface&MockObject $context;
    private UniqueTeamNameValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(TeamRepository::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueTeamNameValidator($this->repository);
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

        $this->validator->validate(null, new UniqueTeamName());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate('', new UniqueTeamName());
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validate(123, new UniqueTeamName());
    }

    public function testValidatePassesWhenNoExistingTeamFound(): void
    {
        $this->repository->method('findOneBy')
            ->with(['name' => 'New Team'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('New Team', new UniqueTeamName());
    }

    public function testValidatePassesWhenUpdatingSameTeam(): void
    {
        $existingTeam = $this->createMock(Team::class);
        $existingTeam->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Existing Team'])
            ->willReturn($existingTeam);

        $dto = new TeamSaveDto(id: 5, name: 'Existing Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('Existing Team', new UniqueTeamName());
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $existingTeam = $this->createMock(Team::class);
        $existingTeam->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Duplicate Name'])
            ->willReturn($existingTeam);

        $dto = new TeamSaveDto(id: 0, name: 'Duplicate Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->with('The team name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);

        $this->validator->validate('Duplicate Name', new UniqueTeamName());
    }

    public function testValidateAddsViolationWhenDifferentTeamHasSameName(): void
    {
        $existingTeam = $this->createMock(Team::class);
        $existingTeam->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Conflicting Name'])
            ->willReturn($existingTeam);

        $dto = new TeamSaveDto(id: 10, name: 'Conflicting Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->with('The team name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);

        $this->validator->validate('Conflicting Name', new UniqueTeamName());
    }

    public function testValidateAddsViolationWhenContextObjectIsNotDto(): void
    {
        $existingTeam = $this->createMock(Team::class);
        $existingTeam->method('getId')->willReturn(5);

        $this->repository->method('findOneBy')
            ->with(['name' => 'Some Name'])
            ->willReturn($existingTeam);

        // Context object is not a TeamSaveDto (simulating raw validation without DTO)
        $this->context->method('getObject')->willReturn(new stdClass());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('Some Name', new UniqueTeamName());
    }
}
