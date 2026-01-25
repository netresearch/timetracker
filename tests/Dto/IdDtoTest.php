<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\IdDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for IdDto.
 *
 * @internal
 */
#[CoversClass(IdDto::class)]
final class IdDtoTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaultValue(): void
    {
        $dto = new IdDto();

        self::assertSame(0, $dto->id);
    }

    public function testConstructorWithProvidedValue(): void
    {
        $dto = new IdDto(id: 42);

        self::assertSame(42, $dto->id);
    }

    // ==================== fromRequest tests ====================

    public function testFromRequestWithId(): void
    {
        $request = new Request([], ['id' => '123']);

        $dto = IdDto::fromRequest($request);

        self::assertSame(123, $dto->id);
    }

    public function testFromRequestWithMissingId(): void
    {
        $request = new Request();

        $dto = IdDto::fromRequest($request);

        self::assertSame(0, $dto->id);
    }

    public function testFromRequestWithStringId(): void
    {
        $request = new Request([], ['id' => '456']);

        $dto = IdDto::fromRequest($request);

        self::assertSame(456, $dto->id);
    }

    public function testFromRequestWithZeroId(): void
    {
        $request = new Request([], ['id' => '0']);

        $dto = IdDto::fromRequest($request);

        self::assertSame(0, $dto->id);
    }
}
