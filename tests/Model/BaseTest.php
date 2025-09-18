<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\Base;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function count;

class TestModel extends Base
{
    protected string $name = 'Name';

    protected int $id = 500;

    protected string $workspace = 'internal';

    protected bool $active = true;

    public function getName(): string
    {
        return $this->name;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getWorkspace(): string
    {
        return $this->workspace;
    }

    public function getActive(): bool
    {
        return $this->active;
    }
}

/**
 * @internal
 *
 * @coversNothing
 */
final class BaseTest extends TestCase
{
    public function testBaseModelByTestModel(): void
    {
        $testModel = new TestModel();
        $result = $testModel->toArray();

        self::assertSame(4, count($result));
        self::assertTrue(array_key_exists('id', $result));
        self::assertSame(500, $result['id']);
        self::assertTrue($result['active']);
    }
}