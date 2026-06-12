<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\UserSaveDto;
use App\Entity\User;
use App\Validator\Constraints\UniqueUsername;
use App\Validator\Constraints\UniqueUsernameValidator;
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
 * Unit tests for UniqueUsernameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueUsernameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueUsernameValidatorTest extends TestCase
{
    use UniquenessValidatorMockTrait;

    private EntityManagerInterface&MockObject $entityManager;
    private ExecutionContextInterface&MockObject $context;
    private UniqueUsernameValidator $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueUsernameValidator($this->entityManager);
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

        $this->validator->validateInContext(null, new UniqueUsername(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validateInContext('', new UniqueUsername(), $this->context);
    }

    public function testValidatePassesWhenNoExistingUserFound(): void
    {
        $this->mockRepositoryResult($this->entityManager, User::class, null);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'NUS', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('newuser', new UniqueUsername(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameUser(): void
    {
        // When updating the same user, the andWhere clause excludes it,
        // so the query returns null (no other user with that username)
        $this->mockRepositoryResult($this->entityManager, User::class, null);

        $dto = new UserSaveDto(id: 5, username: 'existinguser', abbr: 'EXI', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('existinguser', new UniqueUsername(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateUsernameFoundForNewUser(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, User::class, $existingUser);

        $dto = new UserSaveDto(id: 0, username: 'duplicateuser', abbr: 'DUP', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('duplicateuser', new UniqueUsername(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentUserHasSameUsername(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, User::class, $existingUser);

        $dto = new UserSaveDto(id: 10, username: 'conflictinguser', abbr: 'CON', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('conflictinguser', new UniqueUsername(), $this->context);
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($this->entityManager, User::class, $existingUser);

        // Context object is not a UserSaveDto
        $this->context->method('getObject')->willReturn(new stdClass());

        $this->expectSingleViolation($this->context);

        $this->validator->validateInContext('someuser', new UniqueUsername(), $this->context);
    }
}
