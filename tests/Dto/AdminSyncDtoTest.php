<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\AdminSyncDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for AdminSyncDto.
 *
 * @internal
 */
#[CoversClass(AdminSyncDto::class)]
final class AdminSyncDtoTest extends TestCase
{
    public function testConstructorWithDefaultValue(): void
    {
        $dto = new AdminSyncDto();

        self::assertSame(0, $dto->project);
    }

    public function testConstructorWithCustomValue(): void
    {
        $dto = new AdminSyncDto(project: 42);

        self::assertSame(42, $dto->project);
    }

    public function testFromRequestWithProjectQuery(): void
    {
        $request = Request::create('/admin/sync', 'GET', ['project' => '123']);

        $dto = AdminSyncDto::fromRequest($request);

        self::assertSame(123, $dto->project);
    }

    public function testFromRequestWithNullProject(): void
    {
        $request = Request::create('/admin/sync', 'GET', []);

        $dto = AdminSyncDto::fromRequest($request);

        self::assertSame(0, $dto->project);
    }

    public function testFromRequestWithStringZeroProject(): void
    {
        $request = Request::create('/admin/sync', 'GET', ['project' => '0']);

        $dto = AdminSyncDto::fromRequest($request);

        self::assertSame(0, $dto->project);
    }

    public function testFromRequestWithNonNumericProject(): void
    {
        $request = Request::create('/admin/sync', 'GET', ['project' => 'abc']);

        $dto = AdminSyncDto::fromRequest($request);

        // (int) 'abc' = 0
        self::assertSame(0, $dto->project);
    }
}
