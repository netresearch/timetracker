<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\CustomerSaveDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for CustomerSaveDto.
 *
 * @internal
 */
#[CoversClass(CustomerSaveDto::class)]
final class CustomerSaveDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaults(): void
    {
        $dto = new CustomerSaveDto();

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertFalse($dto->active);
        self::assertFalse($dto->global);
        self::assertSame([], $dto->teams);
    }

    public function testConstructorWithAllValues(): void
    {
        $dto = new CustomerSaveDto(
            id: 42,
            name: 'Acme Corp',
            active: true,
            global: true,
            teams: [1, 2, 3],
        );

        self::assertSame(42, $dto->id);
        self::assertSame('Acme Corp', $dto->name);
        self::assertTrue($dto->active);
        self::assertTrue($dto->global);
        self::assertSame([1, 2, 3], $dto->teams);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithAllFields(): void
    {
        $request = new Request([], [
            'id' => '10',
            'name' => 'Test Customer',
            'active' => '1',
            'global' => '1',
            'teams' => ['5', '10'],
        ]);

        $dto = CustomerSaveDto::fromRequest($request);

        self::assertSame(10, $dto->id);
        self::assertSame('Test Customer', $dto->name);
        self::assertTrue($dto->active);
        self::assertTrue($dto->global);
        self::assertSame(['5', '10'], $dto->teams);
    }

    public function testFromRequestWithMissingFields(): void
    {
        $request = new Request();

        $dto = CustomerSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
        self::assertFalse($dto->active);
        self::assertFalse($dto->global);
        self::assertSame([], $dto->teams);
    }

    public function testFromRequestWithPartialFields(): void
    {
        $request = new Request([], [
            'name' => 'Partial Customer',
            'active' => '1',
        ]);

        $dto = CustomerSaveDto::fromRequest($request);

        self::assertSame(0, $dto->id);
        self::assertSame('Partial Customer', $dto->name);
        self::assertTrue($dto->active);
        self::assertFalse($dto->global);
        self::assertSame([], $dto->teams);
    }

    public function testFromRequestConvertsActiveFalseCorrectly(): void
    {
        $request = new Request([], [
            'name' => 'Customer',
            'active' => '',
        ]);

        $dto = CustomerSaveDto::fromRequest($request);

        self::assertFalse($dto->active);
    }

    public function testFromRequestPreservesTeamArrayValues(): void
    {
        $request = new Request([], [
            'teams' => ['a' => 1, 'b' => 2, 'c' => 3],
        ]);

        $dto = CustomerSaveDto::fromRequest($request);

        // array_values reindexes the array
        self::assertSame([1, 2, 3], $dto->teams);
    }
}
