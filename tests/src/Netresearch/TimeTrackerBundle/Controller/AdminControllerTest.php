<?php

use Tests\BaseTest;

class AdminControllerTest extends BaseTest
{
    public function testUserSaveUserExists()
    {
        $parameter = [
            'id' => null,
            'username' => 'unittest',
            'abbr' => 'UTT',
            'teams' => [1],
            'type' => 'PL',
            'locale' => 'en',
        ];
        $this->client->request('POST', '/user/save', $parameter);
        $this->assertStatusCode(406);
        $this->assertMessage('The user name abreviation provided already exists.');
        $this->assertContentType('text/html');
    }
    //next methods for testing
    // public function testUserNotPL(){/* braucht einen nicht PL in der db, also andere testdaten */}
    // public function testUserUsernameToShort(){}
    // public function testUserUsernameExists(){}
    // public function testUserAbbrToShort(){}
    // public function testUserAbbrExists(){}
    // public function testUserTeamIDExists(){}
    // public function testUserNoTeam(){/* */}
    // public function testUserUserCreates(){/* compare json return obj */}
}
