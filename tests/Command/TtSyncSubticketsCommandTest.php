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
    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;
        parent::ensureKernelShutdown();
        if ($wasBooted) {
            @\restore_exception_handler();
        }
    }
    public function testRunsForAllProjectsWithTicketSystem(): void
    {
        self::bootKernel();

        /** @var SubticketSyncService&MockObject $syncService */
        /** @var SubticketSyncService&MockObject $mock */
        $mock = $this->getMockBuilder(SubticketSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $mock->expects($this->exactly(2))
            ->method('syncProjectSubtickets')
            ->willReturnOnConsecutiveCalls(['X-1','X-2'], []);

        // Repository mock that returns a minimal query builder-like object
        $project = (new Project())->setId(1)->setName('Alpha');
        $p2 = (new Project())->setId(2)->setName('Beta');
        $projectRepo = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();

        $queryMock = $this->getMockBuilder(\Doctrine\ORM\AbstractQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMockForAbstractClass();
        $queryMock->method('getResult')->willReturn([$project, $p2]);

        $qbMock = $this->getMockBuilder(\Doctrine\ORM\QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['where', 'getQuery'])
            ->getMock();
        $qbMock->method('where')->willReturn($qbMock);
        $qbMock->method('getQuery')->willReturn($queryMock);

        $projectRepo->method('createQueryBuilder')->willReturn($qbMock);

        /** @var EntityManagerInterface&MockObject $em */
        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $em->method('getRepository')->willReturn($projectRepo);

        $ttSyncSubticketsCommand = new TtSyncSubticketsCommand($mock, $em);
        $application = new Application();
        $application->add($ttSyncSubticketsCommand);

        $commandTester = new CommandTester($application->find('tt:sync-subtickets'));
        $exitCode = $commandTester->execute([]);

        $this->assertSame(0, $exitCode);
    }

    public function testFailsWhenProjectIdNotFound(): void
    {
        self::bootKernel();

        /** @var SubticketSyncService&MockObject $mock */
        $mock = $this->getMockBuilder(SubticketSyncService::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Repository mock with find() returning null
        $projectRepo = $this->getMockBuilder(\Doctrine\ORM\EntityRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['find'])
            ->getMock();
        $projectRepo->method('find')->willReturn(null);

        /** @var EntityManagerInterface&MockObject $em */
        $em = $this->getMockBuilder(EntityManagerInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $em->method('getRepository')->willReturn($projectRepo);

        $ttSyncSubticketsCommand = new TtSyncSubticketsCommand($mock, $em);
        $application = new Application();
        $application->add($ttSyncSubticketsCommand);

        $commandTester = new CommandTester($application->find('tt:sync-subtickets'));
        $exitCode = $commandTester->execute(['project' => 999]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Project does not exist', $commandTester->getDisplay());
    }
}


