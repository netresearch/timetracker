<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\UserSaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

/**
 * Unit tests for UserSaveDto.
 *
 * @internal
 */
#[CoversClass(UserSaveDto::class)]
final class UserSaveDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaults(): void
    {
        $dto = new UserSaveDto();

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->username);
        self::assertSame('', $dto->abbr);
        self::assertSame('', $dto->type);
        self::assertSame('', $dto->locale);
        self::assertSame([], $dto->teams);
        self::assertSame('', $dto->password);
        self::assertNull($dto->authSource);
    }

    public function testConstructorWithAllValues(): void
    {
        $dto = new UserSaveDto(
            id: 42,
            username: 'john.doe',
            abbr: 'JDO',
            type: 'DEV',
            locale: 'de_DE',
            teams: [1, 2, 3],
        );

        self::assertSame(42, $dto->id);
        self::assertSame('john.doe', $dto->username);
        self::assertSame('JDO', $dto->abbr);
        self::assertSame('DEV', $dto->type);
        self::assertSame('de_DE', $dto->locale);
        self::assertSame([1, 2, 3], $dto->teams);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithAllFields(): void
    {
        $request = new Request([], [
            'id' => '10',
            'username' => 'jane.smith',
            'abbr' => 'JSM',
            'type' => 'PL',
            'locale' => 'en_US',
            'teams' => ['5', '10'],
        ]);

        $dto = UserSaveDto::fromRequest($request);

        self::assertSame(10, $dto->id);
        self::assertSame('jane.smith', $dto->username);
        self::assertSame('JSM', $dto->abbr);
        self::assertSame('PL', $dto->type);
        self::assertSame('en_US', $dto->locale);
        self::assertSame(['5', '10'], $dto->teams);
    }

    public function testFromRequestWithMissingFields(): void
    {
        $request = new Request();

        $dto = UserSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->username);
        self::assertSame('', $dto->abbr);
        self::assertSame('', $dto->type);
        self::assertSame('', $dto->locale);
        self::assertSame([], $dto->teams);
    }

    public function testFromRequestWithPartialFields(): void
    {
        $request = new Request([], [
            'username' => 'bob.wilson',
            'abbr' => 'BWI',
        ]);

        $dto = UserSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('bob.wilson', $dto->username);
        self::assertSame('BWI', $dto->abbr);
        self::assertSame('', $dto->type);
        self::assertSame('', $dto->locale);
        self::assertSame([], $dto->teams);
    }

    public function testFromRequestPreservesTeamArrayValues(): void
    {
        $request = new Request([], [
            'teams' => ['a' => 1, 'b' => 2, 'c' => 3],
        ]);

        $dto = UserSaveDto::fromRequest($request);

        // array_values reindexes the array
        self::assertSame([1, 2, 3], $dto->teams);
    }

    // ==================== validateTeams tests ====================

    public function testValidateTeamsAddsViolationWhenEmpty(): void
    {
        $dto = new UserSaveDto(teams: []);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())
            ->method('atPath')
            ->with('teams')
            ->willReturnSelf();
        $violationBuilder->expects(self::once())
            ->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Every user must belong to at least one team')
            ->willReturn($violationBuilder);

        $dto->validateTeams($context);
    }

    public function testValidateTeamsDoesNotAddViolationWhenTeamsExist(): void
    {
        $dto = new UserSaveDto(teams: [1, 2]);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())
            ->method('buildViolation');

        $dto->validateTeams($context);
    }

    // ==================== password field tests ====================

    public function testFromRequestReadsPasswordAndAuthSource(): void
    {
        $request = new Request([], [
            'username' => 'jane',
            'password' => 'sup3rsecret',
            'authSource' => 'local',
        ]);

        $dto = UserSaveDto::fromRequest($request);

        self::assertSame('sup3rsecret', $dto->password);
        self::assertSame(UserSaveDto::AUTH_LOCAL, $dto->authSource);
    }

    public function testPasswordDefaultsEmptyAndAuthSourceDefaultsNull(): void
    {
        $dto = UserSaveDto::fromRequest(new Request());

        self::assertSame('', $dto->password);
        // Omitted → null ("no source change"), never coerced to a concrete value.
        self::assertNull($dto->authSource);
    }

    public function testOmittedAuthSourceValidatesLikeLocalForPasswordLength(): void
    {
        // A legacy client sending only a (too-short) password is still length-checked.
        $short = new UserSaveDto(password: 'short', authSource: null);

        $builder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $builder->expects(self::once())->method('atPath')->with('password')->willReturnSelf();
        $builder->expects(self::once())->method('addViolation');
        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Password must be at least 8 characters.')
            ->willReturn($builder);

        $short->validatePassword($context);
    }

    public function testOmittedAuthSourceWithoutPasswordIsAccepted(): void
    {
        $dto = new UserSaveDto(password: '', authSource: null);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $dto->validatePassword($context);
    }

    public function testValidateRejectsUnknownAuthSource(): void
    {
        $dto = new UserSaveDto(authSource: 'bogus');

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('atPath')->with('authSource')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Authentication source must be either local or LDAP.')
            ->willReturn($violationBuilder);

        $dto->validatePassword($context);
    }

    public function testValidatePasswordRejectsTooShortForLocal(): void
    {
        $dto = new UserSaveDto(password: 'short', authSource: UserSaveDto::AUTH_LOCAL);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('atPath')->with('password')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('Password must be at least 8 characters.')
            ->willReturn($violationBuilder);

        $dto->validatePassword($context);
    }

    public function testValidatePasswordAcceptsLongEnoughForLocal(): void
    {
        $dto = new UserSaveDto(password: 'longenough', authSource: UserSaveDto::AUTH_LOCAL);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $dto->validatePassword($context);
    }

    public function testValidatePasswordRejectsPasswordUnderLdap(): void
    {
        // Supplying a password while choosing the directory is contradictory.
        $dto = new UserSaveDto(password: 'longenough', authSource: UserSaveDto::AUTH_LDAP);

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder->expects(self::once())->method('atPath')->with('password')->willReturnSelf();
        $violationBuilder->expects(self::once())->method('addViolation');

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::once())
            ->method('buildViolation')
            ->with('An LDAP account has no local password — choose local authentication to set one.')
            ->willReturn($violationBuilder);

        $dto->validatePassword($context);
    }

    public function testValidatePasswordAcceptsLdapWithoutPassword(): void
    {
        $dto = new UserSaveDto(password: '', authSource: UserSaveDto::AUTH_LDAP);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $dto->validatePassword($context);
    }

    public function testValidatePasswordAcceptsLocalWithoutPassword(): void
    {
        // At the DTO level this is fine (keep the existing hash); the entity-aware
        // "a brand-new local account needs a password" check lives in SaveUserAction.
        $dto = new UserSaveDto(password: '', authSource: UserSaveDto::AUTH_LOCAL);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context->expects(self::never())->method('buildViolation');

        $dto->validatePassword($context);
    }
}
