<?php

declare(strict_types=1);

namespace Tests\Validator\Constraints;

use App\Dto\CustomerSaveDto;
use App\Validator\Constraints\CustomerTeamsRequired;
use App\Validator\Constraints\CustomerTeamsRequiredValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for CustomerTeamsRequiredValidator.
 *
 * @internal
 */
#[CoversClass(CustomerTeamsRequiredValidator::class)]
final class CustomerTeamsRequiredValidatorTest extends TestCase
{
    private ExecutionContextInterface&MockObject $context;
    private CustomerTeamsRequiredValidator $validator;

    protected function setUp(): void
    {
        $this->context = $this->createMock(ExecutionContextInterface::class);
        $this->validator = new CustomerTeamsRequiredValidator();
        $this->validator->initialize($this->context);
    }

    // ==================== Constraint type tests ====================

    public function testValidateThrowsOnWrongConstraintType(): void
    {
        $constraint = $this->createMock(Constraint::class);

        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(new CustomerSaveDto(), $constraint);
    }

    // ==================== Non-CustomerSaveDto value tests ====================

    public function testValidateSkipsNonCustomerSaveDto(): void
    {
        $this->context->expects(self::never())->method('buildViolation');

        // Validate with a string instead of CustomerSaveDto
        $this->validator->validate('not a dto', new CustomerTeamsRequired());
    }

    public function testValidateSkipsNullValue(): void
    {
        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate(null, new CustomerTeamsRequired());
    }

    // ==================== Valid cases (no violation) ====================

    public function testValidatePassesWhenGlobalIsTrue(): void
    {
        $dto = new CustomerSaveDto(
            id: 0,
            name: 'Test',
            active: true,
            global: true,  // Global customer doesn't need teams
            teams: [],
        );

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate($dto, new CustomerTeamsRequired());
    }

    public function testValidatePassesWhenNotGlobalButHasTeams(): void
    {
        $dto = new CustomerSaveDto(
            id: 0,
            name: 'Test',
            active: true,
            global: false,  // Not global, but has teams
            teams: [1, 2, 3],
        );

        $this->context->expects(self::never())->method('buildViolation');

        $this->validator->validate($dto, new CustomerTeamsRequired());
    }

    // ==================== Invalid cases (violation) ====================

    public function testValidateFailsWhenNotGlobalAndNoTeams(): void
    {
        $dto = new CustomerSaveDto(
            id: 0,
            name: 'Test',
            active: true,
            global: false,  // Not global
            teams: [],      // No teams - this should fail
        );

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->with('Teams must be specified when customer is not global.')
            ->willReturn($violationBuilder);

        $this->validator->validate($dto, new CustomerTeamsRequired());
    }

    public function testValidateUsesCustomMessage(): void
    {
        $dto = new CustomerSaveDto(
            id: 0,
            name: 'Test',
            active: true,
            global: false,
            teams: [],
        );

        $constraint = new CustomerTeamsRequired();
        $constraint->message = 'Custom error message';

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('addViolation');

        $this->context->expects(self::once())
            ->method('buildViolation')
            ->with('Custom error message')
            ->willReturn($violationBuilder);

        $this->validator->validate($dto, $constraint);
    }
}
