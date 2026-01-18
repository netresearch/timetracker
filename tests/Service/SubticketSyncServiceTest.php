<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Service\Integration\Jira\JiraOAuthApiFactory;
use App\Service\SubticketSyncService;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
#[AllowMockObjectsWithoutExpectations]
final class SubticketSyncServiceTest extends TestCase
{
    private function createService(
        ManagerRegistry $managerRegistry,
        JiraOAuthApiFactory $jiraOAuthApiFactory,
    ): SubticketSyncService {
        // SubticketSyncService signature changed to (ManagerRegistry, JiraOAuthApiFactory)
        return new SubticketSyncService($managerRegistry, $jiraOAuthApiFactory);
    }

    public function testProjectNotFoundThrows404(): void
    {
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn(null);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Project does not exist');
        $this->expectExceptionCode(404);
        $subticketSyncService->syncProjectSubtickets(123);
    }

    public function testNoTicketSystemConfigured(): void
    {
        $project = $this->createConfiguredMock(Project::class, [
            'getTicketSystem' => null,
        ]);

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No ticket system configured for project');
        $this->expectExceptionCode(400);
        $subticketSyncService->syncProjectSubtickets(1);
    }

    public function testNullMainTicketsClearsSubticketsIfPresent(): void
    {
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($this->createMock(TicketSystem::class));
        $project->method('getJiraTicket')->willReturn(null);
        $project->method('getSubtickets')->willReturn('something');
        $project->expects(self::once())->method('setSubtickets')->with('');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);

        $om = $this->createMock(ObjectManager::class);
        $om->expects(self::once())->method('persist')->with($project);
        $om->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $registry->method('getManager')->willReturn($om);
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $result = $subticketSyncService->syncProjectSubtickets(1);
        self::assertSame([], $result);
    }

    public function testNoProjectLeadThrows(): void
    {
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($this->createMock(TicketSystem::class));
        $project->method('getJiraTicket')->willReturn('ABC-1');
        $project->method('getProjectLead')->willReturn(null);
        $project->method('getName')->willReturn('P1');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Project has no lead user');
        $this->expectExceptionCode(400);
        $subticketSyncService->syncProjectSubtickets(1);
    }

    public function testNoTokenForTicketSystemThrows(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $user->method('getTicketSystemAccessToken')->with($ticketSystem)->willReturn(null);
        $user->method('getUsername')->willReturn('dev');

        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getJiraTicket')->willReturn('ABC-1');
        $project->method('getProjectLead')->willReturn($user);
        $project->method('getName')->willReturn('P1');

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Project user has no token for ticket system');
        $this->expectExceptionCode(400);
        $subticketSyncService->syncProjectSubtickets(1);
    }

    public function testHappyPathMergesAndSortsSubtickets(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $user->method('getTicketSystemAccessToken')->with($ticketSystem)->willReturn('tok');
        $user->method('getUsername')->willReturn('dev');

        $mock = $this->getMockBuilder(Project::class)
            ->onlyMethods(['getTicketSystem', 'getJiraTicket', 'getProjectLead', 'setSubtickets'])
            ->getMock();
        $mock->method('getTicketSystem')->willReturn($ticketSystem);
        $mock->method('getJiraTicket')->willReturn('DEF-2, ABC-1');
        $mock->method('getProjectLead')->willReturn($user);
        $mock->expects(self::once())->method('setSubtickets')->with(self::callback(static function (string $arg): bool {
            $expected = ['ABC-1', 'ABC-2', 'DEF-2'];
            sort($expected);
            $actualArray = explode(',', $arg);
            sort($actualArray);

            return $expected === $actualArray;
        }));

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($mock);

        $om = $this->createMock(ObjectManager::class);
        $om->expects(self::once())->method('persist')->with($mock);
        $om->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $registry->method('getManager')->willReturn($om);

        $jiraApi = $this->createMock(\App\Service\Integration\Jira\JiraOAuthApiService::class);
        $jiraApi->expects(self::exactly(2))
            ->method('getSubtickets')
            ->willReturnCallback(static fn (string $main): array => 'ABC-1' === $main ? ['ABC-2'] : []);

        $factory = $this->createMock(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($jiraApi);
        assert($factory instanceof JiraOAuthApiFactory);

        $subticketSyncService = $this->createService($registry, $factory);
        $result = $subticketSyncService->syncProjectSubtickets(1);

        self::assertSame(['ABC-1', 'ABC-2', 'DEF-2'], $result);
    }
}
