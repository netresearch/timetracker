<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;

class StatusControllerTest extends BaseTest
{
    public function testCheckAction()
    {
        $expectedJson = array(
            'loginStatus' => true,
        );
        $this->client->request('GET', '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}

