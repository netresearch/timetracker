<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\TeamSaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for TeamSaveDto.
 *
 * @internal
 */
#[CoversClass(TeamSaveDto::class)]
final class TeamSaveDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaults(): void
    {
        $dto = new TeamSaveDto();

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertSame(0, $dto->lead_user_id);
    }

    public function testConstructorWithAllValues(): void
    {
        $dto = new TeamSaveDto(
            id: 5,
            name: 'Development Team',
            lead_user_id: 42,
        );

        self::assertSame(5, $dto->id);
        self::assertSame('Development Team', $dto->name);
        self::assertSame(42, $dto->lead_user_id);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithAllFields(): void
    {
        $request = new Request([], [
            'id' => '10',
            'name' => 'QA Team',
            'lead_user_id' => '25',
        ]);

        $dto = TeamSaveDto::fromRequest($request);

        self::assertSame(10, $dto->id);
        self::assertSame('QA Team', $dto->name);
        self::assertSame(25, $dto->lead_user_id);
    }

    public function testFromRequestWithMissingFields(): void
    {
        $request = new Request();

        $dto = TeamSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertSame(0, $dto->lead_user_id);
    }

    public function testFromRequestWithPartialFields(): void
    {
        $request = new Request([], [
            'name' => 'Support Team',
        ]);

        $dto = TeamSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('Support Team', $dto->name);
        self::assertSame(0, $dto->lead_user_id);
    }

    public function testFromRequestConvertsStringToInt(): void
    {
        $request = new Request([], [
            'id' => '99',
            'lead_user_id' => '100',
        ]);

        $dto = TeamSaveDto::fromRequest($request);

        self::assertSame(99, $dto->id);
        self::assertSame(100, $dto->lead_user_id);
    }
}
