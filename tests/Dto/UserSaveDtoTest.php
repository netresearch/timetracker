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
}
