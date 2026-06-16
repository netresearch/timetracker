<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\HolidayDeleteDto;
use App\Dto\HolidaySaveDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for the holiday save/delete payloads (day-keyed, immutable).
 *
 * @internal
 *
 * @coversNothing
 */
final class HolidayDtoTest extends TestCase
{
    public function testSaveDtoDefaultsAreEmpty(): void
    {
        $dto = new HolidaySaveDto();

        self::assertSame('', $dto->day);
        self::assertSame('', $dto->name);
    }

    public function testSaveDtoFromRequestReadsDayAndName(): void
    {
        $request = new Request();
        $request->request->set('day', '2026-12-24');
        $request->request->set('name', 'Christmas Eve');

        $dto = HolidaySaveDto::fromRequest($request);

        self::assertSame('2026-12-24', $dto->day);
        self::assertSame('Christmas Eve', $dto->name);
    }

    public function testDeleteDtoFromRequestReadsDay(): void
    {
        $request = new Request();
        $request->request->set('day', '2026-12-24');

        $dto = HolidayDeleteDto::fromRequest($request);

        self::assertSame('2026-12-24', $dto->day);
    }

    public function testDeleteDtoDefaultsToEmptyDay(): void
    {
        self::assertSame('', new HolidayDeleteDto()->day);
    }
}
