<?php

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

class SettingsControllerTest extends AbstractWebTestCase
{
    public function testSaveAction(): void
    {
        $parameter = [
            'locale' => 'de',
            'show_empty_line' => 1,
            'suggest_time' => 1,
            'show_future' => 1,
        ];
        $expectedJson = [
            'success' => true,
            'settings' => [
                'show_empty_line' => 1,
                'suggest_time' => 1,
                'show_future' => 1,
                'user_name' => 'i.myself',
                'type' => 'PL',
                'locale' => 'de',
            ],
            'locale' => 'de',
            'message' => 'Die Konfiguration wurde erfolgreich gespeichert.',
        ];
        $this->client->request('POST', '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder->select('*')
            ->from('users')->where('id = :userId')
            ->setParameter('userId', 1);
        $result = $this->queryBuilder->execute()->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'username' => 'i.myself',
                'abbr' => 'IMY',
                'type' => 'PL',
                'show_empty_line' => 1,
                'suggest_time' => 1,
                'show_future' => 1,
                'locale' => 'de',
            ],
        ];
        $this->assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveActionRejectsGet(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $this->client->request('GET', '/settings/save');
    }

    public function testSaveActionUnauthenticated(): void
    {
        // Reboot client without session to simulate unauthenticated
        $this->ensureKernelShutdown();
        $this->client = static::createClient();

        $parameter = [
            'locale' => 'de',
            'show_empty_line' => 1,
            'suggest_time' => 1,
            'show_future' => 1,
        ];
        $this->client->request('POST', '/settings/save', $parameter);
        // Unauthenticated should redirect to login (302) in this app setup
        $this->assertStatusCode(302);
    }

    public function testSaveActionNormalizesLocale(): void
    {
        $parameter = [
            'locale' => 'en-US',
            'show_empty_line' => 0,
            'suggest_time' => 0,
            'show_future' => 0,
        ];
        $this->client->request('POST', '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('en', $response['locale']);
        $this->assertEquals('en', $response['settings']['locale']);
    }
}
