<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

use function is_array;

/**
 * @internal
 *
 * @coversNothing
 */
final class SettingsControllerTest extends AbstractWebTestCase
{
    public function testSaveAction(): void
    {
        $this->logInSession('i.myself');

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
                'type' => 'ADMIN',
                'locale' => 'de',
            ],
            'locale' => 'de',
            'message' => 'Die Konfiguration wurde erfolgreich gespeichert.',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson, $this->getJsonResponse($this->client->getResponse()));
        self::assertNotNull($this->queryBuilder);
        $queryBuilder = $this->queryBuilder;
        $queryBuilder->select('*')
            ->from('users')->where('id = :userId')
            ->setParameter('userId', 3);
        $queryResult = $queryBuilder->executeQuery();
        $result = $queryResult->fetchAllAssociative();
        $expectedDbEntry = [
            0 => [
                'username' => 'i.myself',
                'abbr' => 'IMY',
                'type' => 'ADMIN',
                'show_empty_line' => 1,
                'suggest_time' => 1,
                'show_future' => 1,
                'locale' => 'de',
            ],
        ];
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveActionRejectsGet(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/settings/save');
        $this->assertStatusCode(405);
    }

    public function testSaveActionUnauthenticated(): void
    {
        // Reboot client without session to simulate unauthenticated
        self::ensureKernelShutdown();
        $this->client = self::createClient();

        $parameter = [
            'locale' => 'de',
            'show_empty_line' => 1,
            'suggest_time' => 1,
            'show_future' => 1,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/settings/save', $parameter);
        // Unauthenticated returns 404 or 302 depending on security setup; assert not 200
        self::assertNotSame(\Symfony\Component\HttpFoundation\Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
    }

    public function testSaveActionNormalizesLocale(): void
    {
        $parameter = [
            'locale' => 'en-US',
            'show_empty_line' => 0,
            'suggest_time' => 0,
            'show_future' => 0,
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);

        // Fix offsetAccess.nonOffsetAccessible: Check if response is array and has expected keys
        if (!is_array($response)) {
            self::fail('Expected JSON response to be an array');
        }

        self::assertArrayHasKey('locale', $response, 'Response should contain locale key');
        self::assertSame('en', $response['locale']);

        self::assertArrayHasKey('settings', $response, 'Response should contain settings key');
        if (!is_array($response['settings'])) {
            self::fail('Expected settings to be an array');
        }
        self::assertArrayHasKey('locale', $response['settings'], 'Settings should contain locale key');
        self::assertSame('en', $response['settings']['locale']);
    }
}
