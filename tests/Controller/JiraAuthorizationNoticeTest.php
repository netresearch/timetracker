<?php

/*
 * Copyright (c) 2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Tests\Controller;

use App\Entity\TicketSystem;
use App\Event\EntryEvent;
use App\Exception\Integration\Jira\JiraApiException;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\Integration\Jira\JiraOAuthApiService;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Tests\AbstractWebTestCase;

use function json_decode;

/**
 * #911: when Jira refuses the user's token, the user is told where to connect
 * Jira — in the entry save response and in Settings — instead of a log line.
 *
 * @internal
 *
 * @coversNothing
 */
final class JiraAuthorizationNoticeTest extends AbstractWebTestCase
{
    private const array SAVE_PARAMETERS = [
        'date' => '2024-03-11',
        'start' => '09:00:00',
        'end' => '10:00:00',
        'project_id' => 1,
        'customer_id' => 1,
        'activity_id' => 1,
    ];

    public function testTheSaveResponseNamesTheTicketSystemThatNeedsAuthorization(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $dispatcher->addListener(EntryEvent::CREATED, function (EntryEvent $entryEvent): void {
            $entryEvent->requireJiraAuthorization($this->ticketSystem(1));
        });

        $data = $this->save();

        self::assertSame([[
            'ticketSystemId' => 1,
            'ticketSystemName' => 'testSystem',
            'url' => '/jira/connect/1',
        ]], $data['jiraAuthorization'] ?? null);
    }

    public function testASaveWithoutAJiraRefusalCarriesNoAuthorizationNotice(): void
    {
        $data = $this->save();

        self::assertArrayNotHasKey('jiraAuthorization', $data);
    }

    public function testConnectRedirectsToTheAuthorizeUrl(): void
    {
        $this->makeTicketSystemOneABookableJira();
        $api = $this->createMock(JiraOAuthApiService::class);
        $api->expects(self::once())->method('getAuthorizationUrl')
            ->willReturn('https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=fresh');
        $factory = self::createStub(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($api);
        self::getContainer()->set(JiraOAuthApiFactory::class, $factory);

        $this->client->request(Request::METHOD_GET, '/jira/connect/1');

        $this->assertStatusCode(302);
        self::assertSame(
            'https://jira.example.com/plugins/servlet/oauth/authorize?oauth_token=fresh',
            $this->client->getResponse()->headers->get('Location'),
        );
    }

    public function testConnectReportsAJiraThatCannotIssueARequestToken(): void
    {
        $this->makeTicketSystemOneABookableJira();
        $api = self::createStub(JiraOAuthApiService::class);
        $api->method('getAuthorizationUrl')->willThrowException(new JiraApiException('Jira unreachable', 0));
        $factory = self::createStub(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($api);
        self::getContainer()->set(JiraOAuthApiFactory::class, $factory);

        $this->client->request(Request::METHOD_GET, '/jira/connect/1');

        $this->assertStatusCode(502);
    }

    public function testConnectRefusesATicketSystemThatIsNotJira(): void
    {
        // ticket system 1 is seeded with an empty type
        $this->client->request(Request::METHOD_GET, '/jira/connect/1');

        $this->assertStatusCode(404);
    }

    public function testSettingsListsBookableJiraSystemsWithTheirConnectUrl(): void
    {
        $this->makeTicketSystemOneABookableJira();

        $this->client->request(Request::METHOD_GET, '/api/v2/jira/connections', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([[
            'ticket_system_id' => 1,
            'ticket_system_name' => 'testSystem',
            'connected' => false,
            'connect_url' => '/jira/connect/1',
        ]], $data['connections'] ?? null);
    }

    public function testSettingsListMarksASystemWithAStoredTokenAsConnected(): void
    {
        $this->makeTicketSystemOneABookableJira();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("INSERT INTO users_ticket_systems (user_id, ticket_system_id, accesstoken, tokensecret, avoidconnection) VALUES (1, 1, 'stored', 'secret', 0)");

        $this->client->request(Request::METHOD_GET, '/api/v2/jira/connections', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['connections'] ?? null);
        self::assertIsArray($data['connections'][0] ?? null);
        self::assertTrue($data['connections'][0]['connected'] ?? null);
    }

    public function testSettingsListOmitsTicketSystemsThatAreNotJira(): void
    {
        $this->client->request(Request::METHOD_GET, '/api/v2/jira/connections', [], [], ['HTTP_ACCEPT' => 'application/json']);

        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertSame([], $data['connections'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function save(): array
    {
        $this->client->request(Request::METHOD_POST, '/tracking/save', self::SAVE_PARAMETERS, [], ['HTTP_ACCEPT' => 'application/json']);
        $this->assertStatusCode(200);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result'] ?? null);

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function ticketSystem(int $id): TicketSystem
    {
        $doctrine = self::getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $ticketSystem = $doctrine->getRepository(TicketSystem::class)->find($id);
        self::assertInstanceOf(TicketSystem::class, $ticketSystem);

        return $ticketSystem;
    }

    private function makeTicketSystemOneABookableJira(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement("UPDATE ticket_systems SET type = 'JIRA', book_time = 1 WHERE id = 1");
    }
}
