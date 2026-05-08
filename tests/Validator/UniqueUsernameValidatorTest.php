<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\UserSaveDto;
use App\Entity\User;
use App\Validator\Constraints\UniqueUsername;
use App\Validator\Constraints\UniqueUsernameValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
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
 * Unit tests for UniqueUsernameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueUsernameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueUsernameValidatorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ExecutionContextInterface&MockObject $context;
    private UniqueUsernameValidator $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueUsernameValidator($this->entityManager);
        $this->validator->initialize($this->context);
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('test', $constraint);
    }

    public function testValidateReturnsEarlyForNullValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate(null, new UniqueUsername());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate('', new UniqueUsername());
    }

    public function testValidatePassesWhenNoExistingUserFound(): void
    {
        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'NUS', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('newuser', new UniqueUsername());
    }

    public function testValidatePassesWhenUpdatingSameUser(): void
    {
        // When updating the same user, the andWhere clause excludes it,
        // so the query returns null (no other user with that username)
        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 5, username: 'existinguser', abbr: 'EXI', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('existinguser', new UniqueUsername());
    }

    public function testValidateAddsViolationWhenDuplicateUsernameFoundForNewUser(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 0, username: 'duplicateuser', abbr: 'DUP', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('duplicateuser', new UniqueUsername());
    }

    public function testValidateAddsViolationWhenDifferentUserHasSameUsername(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 10, username: 'conflictinguser', abbr: 'CON', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('conflictinguser', new UniqueUsername());
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = self::createStub(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = self::createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        // Context object is not a UserSaveDto
        $this->context->method('getObject')->willReturn(new stdClass());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('someuser', new UniqueUsername());
    }
}
