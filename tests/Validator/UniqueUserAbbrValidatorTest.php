<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\UserSaveDto;
use App\Entity\User;
use App\Validator\Constraints\UniqueUserAbbr;
use App\Validator\Constraints\UniqueUserAbbrValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueUserAbbrValidator.
 *
 * @internal
 */
#[CoversClass(UniqueUserAbbrValidator::class)]
final class UniqueUserAbbrValidatorTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private ExecutionContextInterface&MockObject $context;
    private UniqueUserAbbrValidator $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueUserAbbrValidator($this->entityManager);
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
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate(null, new UniqueUserAbbr());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validate('', new UniqueUserAbbr());
    }

    public function testValidatePassesWhenNoExistingUserFound(): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'NUS', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('NUS', new UniqueUserAbbr());
    }

    public function testValidatePassesWhenUpdatingSameUser(): void
    {
        // When updating the same user, the andWhere clause excludes it,
        // so the query returns null (no other user with that abbr)
        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn(null);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 5, username: 'existinguser', abbr: 'EXI', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('EXI', new UniqueUserAbbr());
    }

    public function testValidateAddsViolationWhenDuplicateAbbrFoundForNewUser(): void
    {
        $existingUser = $this->createMock(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'DUP', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('DUP', new UniqueUserAbbr());
    }

    public function testValidateAddsViolationWhenDifferentUserHasSameAbbr(): void
    {
        $existingUser = $this->createMock(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        $dto = new UserSaveDto(id: 10, username: 'anotheruser', abbr: 'CON', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('CON', new UniqueUserAbbr());
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingUser = $this->createMock(User::class);
        $existingUser->method('getId')->willReturn(5);

        $query = $this->createMock(Query::class);
        $query->method('getOneOrNullResult')->willReturn($existingUser);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturn($queryBuilder);

        $this->entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($repository);

        // Context object is not a UserSaveDto
        $this->context->method('getObject')->willReturn(new stdClass());

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);

        $this->validator->validate('ABC', new UniqueUserAbbr());
    }
}
