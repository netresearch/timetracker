<?php

declare(strict_types=1);

namespace Tests\Model;

use App\Model\Base;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function count;

class TestModel extends Base
{
    protected $name = 'Name';

    protected $id = 500;

    protected $workspace = 'internal';

    protected $active = true;

    public function getName()
    {
        return $this->name;
    }

    public function getId()
    {
        return $this->id;
    }

    public function getWorkspace()
    {
        return $this->workspace;
    }

    public function getActive()
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
