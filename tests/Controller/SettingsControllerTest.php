<?php

namespace Tests\Controller;

use Tests\Base;

class SettingsControllerTest extends Base
{
    public function testSaveAction()
    {
        $parameter = [
            'locale' => 'de',
            'show_empty_line' => 1,
            'suggest_time' => 1,
            'show_future' => 1,
        ];
        $expectedJson = array(
            'success' => true,
            'settings' => array(
                'show_empty_line' => 1,
                'suggest_time' => 1,
                'show_future' => 1,
                'user_name' => 'i.myself',
                'type' => 'PL',
                'locale' => 'de',
            ),
            'locale' => 'de',
            'message' => 'The configuration has been successfully saved.',
        );
        $this->client->request('POST', '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder->select('*')
            ->from('users')->where('id = ?')
            ->setParameter(0, 1);
        $result = $this->queryBuilder->execute()->fetchAll();
        $expectedDbEntry = array(
            0 => array(
                'username' => 'i.myself',
                'abbr' => 'IMY',
                'type' => 'PL',
                'show_empty_line' => 1,
                'suggest_time' => 1,
                'show_future' => 1,
                'locale' => 'de',
            ),
        );
        $this->assertArraySubset($expectedDbEntry, $result);
    }
}
