<?php

declare(strict_types=1);

namespace Tests\Validator;

use App\Dto\ActivitySaveDto;
use App\Entity\Activity;
use App\Repository\ActivityRepository;
use App\Validator\Constraints\UniqueActivityName;
use App\Validator\Constraints\UniqueActivityNameValidator;
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
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate(null, new UniqueActivityName());
    }

    public function testValidateReturnsEarlyForEmptyStringValue(): void
    {
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate('', new UniqueActivityName());
    }

    public function testValidateReturnsEarlyForNonStringValue(): void
    {
        $this->activityRepository->expects(self::never())->method('findOneBy');

        $this->validator->validate(123, new UniqueActivityName());
    }

    public function testValidatePassesWhenNoExistingActivityFound(): void
    {
        $this->activityRepository->method('findOneBy')
            ->with(['name' => 'New Activity'])
            ->willReturn(null);

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('New Activity', new UniqueActivityName());
    }

    public function testValidatePassesWhenUpdatingSameActivity(): void
    {
        $existingActivity = $this->createMock(Activity::class);
        $existingActivity->method('getId')->willReturn(5);

        $this->activityRepository->method('findOneBy')
            ->with(['name' => 'Existing Activity'])
            ->willReturn($existingActivity);

        $dto = new ActivitySaveDto(id: 5, name: 'Existing Activity');

        $this->context->method('getObject')->willReturn($dto);
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate('Existing Activity', new UniqueActivityName());
    }

    public function testValidateAddsViolationWhenDuplicateNameFound(): void
    {
        $existingActivity = $this->createMock(Activity::class);
        $existingActivity->method('getId')->willReturn(5);

        $this->activityRepository->method('findOneBy')
            ->with(['name' => 'Duplicate Name'])
            ->willReturn($existingActivity);

        $dto = new ActivitySaveDto(id: 0, name: 'Duplicate Name');

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->with('The activity name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);

        $this->validator->validate('Duplicate Name', new UniqueActivityName());
    }

    public function testValidateAddsViolationWhenDifferentActivityHasSameName(): void
    {
        $existingActivity = $this->createMock(Activity::class);
        $existingActivity->method('getId')->willReturn(5);

        $this->activityRepository->method('findOneBy')
            ->with(['name' => 'Conflicting Name'])
            ->willReturn($existingActivity);

        $dto = new ActivitySaveDto(id: 10, name: 'Conflicting Name');

        $this->context->method('getObject')->willReturn($dto);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->method('setParameter')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->method('buildViolation')
            ->with('The activity name "{{ value }}" already exists.')
            ->willReturn($violationBuilder);

        $this->validator->validate('Conflicting Name', new UniqueActivityName());
    }
}
