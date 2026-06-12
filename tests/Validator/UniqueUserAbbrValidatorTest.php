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
 * Unit tests for UniqueUserAbbrValidator.
 *
 * @internal
 */
#[CoversClass(UniqueUserAbbrValidator::class)]
#[AllowMockObjectsWithoutExpectations]
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

        $this->validator->validateInContext(null, new UniqueUserAbbr(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->entityManager->expects(self::never())->method('getRepository');

        $this->validator->validateInContext('', new UniqueUserAbbr(), $this->context);
    }

    public function testValidatePassesWhenNoExistingUserFound(): void
    {
        $this->mockRepositoryResult(null);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'NUS', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('NUS', new UniqueUserAbbr(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameUser(): void
    {
        // When updating the same user, the andWhere clause excludes it,
        // so the query returns null (no other user with that abbr)
        $this->mockRepositoryResult(null);

        $dto = new UserSaveDto(id: 5, username: 'existinguser', abbr: 'EXI', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('EXI', new UniqueUserAbbr(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateAbbrFoundForNewUser(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingUser);

        $dto = new UserSaveDto(id: 0, username: 'newuser', abbr: 'DUP', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('DUP', new UniqueUserAbbr(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentUserHasSameAbbr(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingUser);

        $dto = new UserSaveDto(id: 10, username: 'anotheruser', abbr: 'CON', teams: [1]);
        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('CON', new UniqueUserAbbr(), $this->context);
    }

    public function testValidateHandlesNonDtoContextObject(): void
    {
        $existingUser = self::createStub(User::class);
        $existingUser->method('getId')->willReturn(5);

        $this->mockRepositoryResult($existingUser);

        // Context object is not a UserSaveDto
        $this->context->method('getObject')->willReturn(new stdClass());

        $this->expectSingleViolation();

        $this->validator->validateInContext('ABC', new UniqueUserAbbr(), $this->context);
    }

    /**
     * Mocks the repository chain so the uniqueness query returns $result.
     */
    private function mockRepositoryResult(?object $result): void
    {
        $query = self::createStub(Query::class);
        $query->method('getOneOrNullResult')->willReturn($result);

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
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->willReturn($violationBuilder);
    }
}
