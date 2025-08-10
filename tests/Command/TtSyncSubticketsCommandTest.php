<?php

declare(strict_types=1);

namespace Tests\Command;

use App\Command\TtSyncSubticketsCommand;
use App\Entity\Project;
use App\Service\SubticketSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TtSyncSubticketsCommandTest extends KernelTestCase
{
    public function testRunsForAllProjectsWithTicketSystem(): void
    {
        self::bootKernel();

        /** @var SubticketSyncService&MockObject $syncService */
        /** @var SubticketSyncService&MockObject $syncService */
        $syncService = $this->getMockBuilder(SubticketSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $syncService->expects($this->exactly(2))
            ->method('syncProjectSubtickets')
            ->willReturnOnConsecutiveCalls(['X-1','X-2'], []);

        // Repository stub with createQueryBuilder available
        $p1 = (new Project())->setId(1)->setName('Alpha');
        $p2 = (new Project())->setId(2)->setName('Beta');
        $projectRepo = new class($p1, $p2) {
            public function __construct(private Project $a, private Project $b) {}
            public function createQueryBuilder(string $alias) {
                return new class($this->a, $this->b) {
                    public function __construct(private Project $a, private Project $b) {}
                    public function where($expr) { return $this; }
                    public function getQuery() { return $this; }
                    public function getResult() { return [$this->a, $this->b]; }
                };
            }
        };

        /** @var EntityManagerInterface&MockObject $em */
        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $em->method('getRepository')->willReturn($projectRepo);

        $command = new TtSyncSubticketsCommand($syncService, $em);
        $application = new Application();
        $application->add($command);

        $commandTester = new CommandTester($application->find('tt:sync-subtickets'));
        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function testFailsWhenProjectIdNotFound(): void
    {
        self::bootKernel();

        /** @var SubticketSyncService&MockObject $syncService */
        $syncService = $this->getMockBuilder(SubticketSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Repository stub with find() returning null
        $projectRepo = new class {
            public function find($id) { return null; }
        };

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $em->method('getRepository')->willReturn($projectRepo);

        $command = new TtSyncSubticketsCommand($syncService, $em);
        $application = new Application();
        $application->add($command);

        $commandTester = new CommandTester($application->find('tt:sync-subtickets'));
        $exitCode = $commandTester->execute(['project' => 999]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Project does not exist', $commandTester->getDisplay());
    }
}


