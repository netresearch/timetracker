<?php

declare(strict_types=1);

namespace Tests\Controller;

use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class SettingsControllerTest extends AbstractWebTestCase
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
                'show_empty_line' => true,
                'suggest_time' => true,
                'show_future' => true,
                'user_name' => 'i.myself',
                'type' => 'PL',
                'locale' => 'de',
            ],
            'locale' => 'de',
            'message' => 'Die Konfiguration wurde erfolgreich gespeichert.',
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/settings/save', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->queryBuilder->select('*')
            ->from('users')->where('id = :userId')
            ->setParameter('userId', 1)
        ;
        $result = $this->queryBuilder->executeQuery()->fetchAllAssociative();
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
        self::assertArraySubset($expectedDbEntry, $result);
    }

    public function testSaveActionRejectsGet(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class);
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/settings/save');
    }

    public function testSaveActionUnauthenticated(): void
    {
        // Reboot client without session to simulate unauthenticated
        $this->ensureKernelShutdown();
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
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('en', $response['locale']);
        self::assertSame('en', $response['settings']['locale']);
    }
}
