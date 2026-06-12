<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\ActivitySaveDto;
use App\Entity\Activity;
use App\Repository\ActivityRepository;
use App\Validator\Constraints\UniqueActivityName;
use App\Validator\Constraints\UniqueActivityNameValidator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UniqueActivityNameValidator.
 *
 * @internal
 */
#[CoversClass(UniqueActivityNameValidator::class)]
#[AllowMockObjectsWithoutExpectations]
final class UniqueActivityNameValidatorTest extends TestCase
{
    private ActivityRepository&MockObject $activityRepository;
    private ExecutionContextInterface&MockObject $context;
    private UniqueActivityNameValidator $validator;

    protected function setUp(): void
    {
        $this->activityRepository = $this->createMock(ActivityRepository::class);
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new UniqueActivityNameValidator($this->activityRepository);
    }

    public function testValidateThrowsOnInvalidConstraintType(): void
    {
        $constraint = self::createStub(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validateInContext('test', $constraint, $this->context);
    }

    public function testValidateReturnsEarlyForNullValue(): void
    {
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(null, new UniqueActivityName(), $this->context);
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext('', new UniqueActivityName(), $this->context);
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validateInContext(123, new UniqueActivityName(), $this->context);
    }

    public function testValidatePassesWhenNoExistingActivityFound(): void
    {
        $this->activityRepository->expects(self::once())->method('findOneBy')
            ->with(['name' => 'New Activity'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('New Activity', new UniqueActivityName(), $this->context);
    }

    public function testValidatePassesWhenUpdatingSameActivity(): void
    {
        $this->mockExistingActivityFound('Existing Activity');

        $dto = new ActivitySaveDto(id: 5, name: 'Existing Activity');

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validateInContext('Existing Activity', new UniqueActivityName(), $this->context);
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $this->mockExistingActivityFound('Duplicate Name');

        $dto = new ActivitySaveDto(id: 0, name: 'Duplicate Name');

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Duplicate Name', new UniqueActivityName(), $this->context);
    }

    public function testValidateAddsViolationWhenDifferentActivityHasSameName(): void
    {
        $this->mockExistingActivityFound('Conflicting Name');

        $dto = new ActivitySaveDto(id: 10, name: 'Conflicting Name');

        $this->context->method('getObject')->willReturn($dto);

        $this->expectSingleViolation();

        $this->validator->validateInContext('Conflicting Name', new UniqueActivityName(), $this->context);
    }

    /**
     * Mocks the repository so the uniqueness lookup finds an existing activity (id 5) with $name.
     */
    private function mockExistingActivityFound(string $name): void
    {
        $existingActivity = self::createStub(Activity::class);
        $existingActivity->method('getId')->willReturn(5);

        $this->activityRepository->expects(self::once())->method('findOneBy')
            ->with(['name' => $name])
            ->willReturn($existingActivity);
    }

    private function expectSingleViolation(): void
    {
        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())->method('buildViolation')
            ->with('The activity name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);
    }
}
