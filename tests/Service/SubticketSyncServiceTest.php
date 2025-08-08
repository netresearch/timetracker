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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\RouterInterface;

class SubticketSyncServiceTest extends TestCase
{
    private function createService(
        ManagerRegistry $registry,
        RouterInterface $router,
        JiraOAuthApiFactory $factory
    ): SubticketSyncService {
        return new SubticketSyncService($registry, $router, $factory);
    }

    public function testProjectNotFoundThrows404(): void
    {
        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn(null);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);

        $router = $this->createMock(RouterInterface::class);
        $factory = $this->createMock(JiraOAuthApiFactory::class);

        $service = $this->createService($registry, $router, $factory);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Project does not exist');
        $this->expectExceptionCode(404);
        $service->syncProjectSubtickets(123);
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

        $router = $this->createMock(RouterInterface::class);
        $factory = $this->createMock(JiraOAuthApiFactory::class);

        $service = $this->createService($registry, $router, $factory);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No ticket system configured for project');
        $this->expectExceptionCode(400);
        $service->syncProjectSubtickets(1);
    }

    public function testNullMainTicketsClearsSubticketsIfPresent(): void
    {
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($this->createMock(TicketSystem::class));
        $project->method('getJiraTicket')->willReturn(null);
        $project->method('getSubtickets')->willReturn(['something']);
        $project->expects($this->once())->method('setSubtickets')->with([]);

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);

        $om = $this->createMock(ObjectManager::class);
        $om->expects($this->once())->method('persist')->with($project);
        $om->expects($this->once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $registry->method('getManager')->willReturn($om);

        $router = $this->createMock(RouterInterface::class);
        $factory = $this->createMock(JiraOAuthApiFactory::class);

        $service = $this->createService($registry, $router, $factory);
        $result = $service->syncProjectSubtickets(1);
        $this->assertSame([], $result);
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

        $router = $this->createMock(RouterInterface::class);
        $factory = $this->createMock(JiraOAuthApiFactory::class);

        $service = $this->createService($registry, $router, $factory);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Project has no lead user');
        $this->expectExceptionCode(400);
        $service->syncProjectSubtickets(1);
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

        $router = $this->createMock(RouterInterface::class);
        $factory = $this->createMock(JiraOAuthApiFactory::class);

        $service = $this->createService($registry, $router, $factory);
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Project user has no token for ticket system');
        $this->expectExceptionCode(400);
        $service->syncProjectSubtickets(1);
    }

    public function testHappyPathMergesAndSortsSubtickets(): void
    {
        $ticketSystem = $this->createMock(TicketSystem::class);
        $user = $this->createMock(User::class);
        $user->method('getTicketSystemAccessToken')->with($ticketSystem)->willReturn('tok');
        $user->method('getUsername')->willReturn('dev');

        $project = $this->getMockBuilder(Project::class)
            ->onlyMethods(['getTicketSystem', 'getJiraTicket', 'getProjectLead', 'setSubtickets'])
            ->getMock();
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $project->method('getJiraTicket')->willReturn('DEF-2, ABC-1');
        $project->method('getProjectLead')->willReturn($user);
        $project->expects($this->once())->method('setSubtickets')->with($this->callback(function (array $arg): bool {
            $expected = ['ABC-1', 'ABC-2', 'DEF-2'];
            sort($expected);
            $copy = $arg;
            sort($copy);
            return $expected === $copy;
        }));

        $repo = $this->createMock(ObjectRepository::class);
        $repo->method('find')->willReturn($project);

        $om = $this->createMock(ObjectManager::class);
        $om->expects($this->once())->method('persist')->with($project);
        $om->expects($this->once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getRepository')->willReturn($repo);
        $registry->method('getManager')->willReturn($om);

        $router = $this->createMock(RouterInterface::class);

        $jiraApi = $this->createMock(\App\Helper\JiraOAuthApi::class);
        $jiraApi->expects($this->exactly(2))
            ->method('getSubtickets')
            ->willReturnCallback(function (string $main) {
                return $main === 'ABC-1' ? ['ABC-2'] : [];
            });

        /** @var JiraOAuthApiFactory|MockObject $factory */
        $factory = $this->createMock(JiraOAuthApiFactory::class);
        $factory->method('create')->willReturn($jiraApi);

        $service = $this->createService($registry, $router, $factory);
        $result = $service->syncProjectSubtickets(1);

        $this->assertSame(['ABC-1', 'ABC-2', 'DEF-2'], $result);
    }
}


