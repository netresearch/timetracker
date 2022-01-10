<?php declare(strict_types=1);

namespace App\Tests\Model;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Model\Base;

class TestModel extends Base
{
    protected $name         = 'Name';
    protected $id           = 500;
    protected $workspace    = 'internal';
    protected $active       = true;

    public function getName() {
        return $this->name;
    }

    public function getId() {
        return $this->id;
    }

    public function getWorkspace() {
        return $this->workspace;
    }

    public function getActive() {
        return $this->active;
    }
}

class BaseTest extends TestCase
{
    public function testBaseModelByTestModel(): void
    {
        $testModel = new TestModel();
        $result    = $testModel->toArray();

        static::assertCount(4, $result);
        static::assertArrayHasKey('id', $result);
        static::assertSame(500, $result['id']);
        static::assertTrue($result['active']);
    }
}
