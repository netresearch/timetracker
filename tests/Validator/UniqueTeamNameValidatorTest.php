<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\TeamSaveDto;
use App\Entity\Team;
use App\Repository\TeamRepository;
use App\Validator\Constraints\UniqueTeamName;
use App\Validator\Constraints\UniqueTeamNameValidator;
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
 * Unit tests for UniqueTeamNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueTeamNameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
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

        $this->validator->validateInContext(null, new UniqueTeamName(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext('', new UniqueTeamName(), $this->context);
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->repository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(123, new UniqueTeamName(), $this->context);
    }

    public function testValidatePassesWhenNoExistingTeamFound(): void
    {
        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'New Team'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New Team', new UniqueTeamName(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameTeam(): void
    {
        $this->mockExistingTeamFound('Existing Team');

        $dto = new TeamSaveDto(id: 5, name: 'Existing Team', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('Existing Team', new UniqueTeamName(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $this->mockExistingTeamFound('Duplicate Name');

        $dto = new TeamSaveDto(id: 0, name: 'Duplicate Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Duplicate Name', new UniqueTeamName(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentTeamHasSameName(): void
    {
        $this->mockExistingTeamFound('Conflicting Name');

        $dto = new TeamSaveDto(id: 10, name: 'Conflicting Name', lead_user_id: 1);

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Conflicting Name', new UniqueTeamName(), $this->context);
    }

    public function testValidateAddsViolationWhenContextObjectIsNotDto(): void
    {
        $this->mockExistingTeamFound('Some Name');

        // Context object is not a TeamSaveDto (simulating raw validation without DTO)
        $this->context->method('getObject')->willReturn(new stdClass());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validateInContext('Some Name', new UniqueTeamName(), $this->context);
    }

    /**
     * Mocks the repository so the uniqueness lookup finds an existing team (id 5) with $name.
     */
    private function mockExistingTeamFound(string $name): void
    {
        $existingTeam = self::createStub(Team::class);
        $existingTeam->method('getId')->willReturn(5);

        $this->repository->expects(self::once())->method('findOneBy')
            ->with(['name' => $name])
            ->willReturn($existingTeam);
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())->method('buildViolation')
            ->with('The team name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);
    }
}
