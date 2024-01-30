<?php

namespace Tests\Netresearch\TimeTrackerBundle\Controller;

use Tests\BaseTest;

class AdminControllerTest extends BaseTest
{
    public function testUserSaveUserExists()
    {
        $parameter = [
            'username' => 'unittest',
            'abbr'     => 'IMY',
            //FIXME: 500 when non-existing abb is used
            'teams'    => [1], //req
            'locale'   => 'en',   //req
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('The user name abreviation provided already exists.');
        $this->assertContentType('text/html');
    }
}
