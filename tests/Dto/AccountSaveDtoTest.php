<?php

declare(strict_types=1);

namespace Tests\Dto;

use App\Dto\AccountSaveDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 *
 * @coversNothing
 */
final class AccountSaveDtoTest extends TestCase
{
    public function testConstructorWithDefaultValues(): void
    {
        $dto = new AccountSaveDto();

        self::assertSame(0, $dto->id);
        self::assertSame('', $dto->name);
    }

    public function testConstructorWithCustomValues(): void
    {
        $dto = new AccountSaveDto(id: 7, name: 'Cost Center A');

        self::assertSame(7, $dto->id);
        self::assertSame('Cost Center A', $dto->name);
    }

    public function testFromRequest(): void
    {
        $request = new Request();
        $request->request->set('id', '7');
        $request->request->set('name', 'Cost Center A');

        $dto = AccountSaveDto::fromRequest($request);

        self::assertSame(7, $dto->id);
        self::assertSame('Cost Center A', $dto->name);
    }
}
