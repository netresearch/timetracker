<?php

namespace Tests\Controller;

use Tests\Base;

class StatusControllerTest extends Base
{
    public function testCheckAction(): void
    {
        $expectedJson = [
            'loginStatus' => true,
        ];
        $this->client->request('GET', '/status/check');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }
}
