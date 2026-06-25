<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\UserSaveDto;
use App\Entity\User;
use App\Validator\Constraints\ValidUserAbbr;
use App\Validator\Constraints\ValidUserAbbrValidator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for ValidUserAbbrValidator (1-3 char abbr, grandfathered on edit).
 *
 * @internal
 */
#[CoversClass(ValidUserAbbrValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class ValidUserAbbrValidatorTest extends TestCase
{
    private EntityRepository&MockObject $repository;
    private ExecutionContextInterface&MockObject $context;
    private ValidUserAbbrValidator $validator;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(EntityRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($this->repository);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new ValidUserAbbrValidator($entityManager);
    }

    public function testThrowsOnInvalidConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);
        $this->validator->validateInContext('AB', self::createStub(Constraint::class), $this->context);
    }

    public function testAcceptsAOneToThreeCharAbbrForANewUser(): void
    {
        $this->context->method('getObject')->willReturn(new UserSaveDto(id: 0, abbr: 'ABC'));
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('ABC', new ValidUserAbbr(), $this->context);
    }

    public function testRejectsAnEmptyAbbrForANewUser(): void
    {
        $this->context->method('getObject')->willReturn(new UserSaveDto(id: 0, abbr: ''));
        $this->repository->expects(self::never())->method('find');
        $this->expectSingleViolation();

        $this->validator->validateInContext('', new ValidUserAbbr(), $this->context);
    }

    public function testRejectsAnOverLongAbbrForANewUser(): void
    {
        $this->context->method('getObject')->willReturn(new UserSaveDto(id: 0, abbr: 'ABCD'));
        $this->expectSingleViolation();

        $this->validator->validateInContext('ABCD', new ValidUserAbbr(), $this->context);
    }

    public function testGrandfathersAnUnchangedEmptyAbbrOnEdit(): void
    {
        // A legacy user whose abbr is NULL/empty, re-saved unchanged (e.g. just
        // deactivating), must pass — the length lookup is skipped. The persisted
        // NULL must compare equal to the submitted ''.
        $this->context->method('getObject')->willReturn(new UserSaveDto(id: 7, abbr: ''));
        $this->repository->expects(self::once())->method('find')->with(7)
            ->willReturn($this->stubUser(null));
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('', new ValidUserAbbr(), $this->context);
    }

    public function testStillRejectsChangingToAnOverLongAbbr(): void
    {
        // Changing an existing user's abbr to an over-long value is still rejected.
        $this->context->method('getObject')->willReturn(new UserSaveDto(id: 7, abbr: 'ABCD'));
        $this->repository->expects(self::once())->method('find')->with(7)
            ->willReturn($this->stubUser('AB'));
        $this->expectSingleViolation();

        $this->validator->validateInContext('ABCD', new ValidUserAbbr(), $this->context);
    }

    private function stubUser(?string $abbr): User
    {
        $user = self::createStub(User::class);
        $user->method('getAbbr')->willReturn($abbr);

        return $user;
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')->willReturn($violationBuilder);
    }
}
