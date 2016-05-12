<?php

namespace Netresearch\TimeTrackerBundle\Tests\Model;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Netresearch\TimeTrackerBundle\Model\Base;

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

class BaseTest extends \PHPUnit_Framework_TestCase
{
    public function testBaseModelByTestModel()
    {
        $testModel = new TestModel();
        $result = $testModel->toArray();

        $this->assertEquals(4, count($result));
        $this->assertEquals(true, array_key_exists('id', $result));
        $this->assertEquals(500, $result['id']);
        $this->assertEquals(true, $result['active']);
    }
}
