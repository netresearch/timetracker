<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ProjectTest extends TestCase
{
    public function testFluentInterface(): void
    {
        $project = new Project();

        self::assertSame(
            $project,
            $project
                ->setId(null)
                ->setName(null)
                ->setJiraId(null)
                ->setGlobal(null)
                ->setEstimation(null)
                ->setOffer(null)
                ->setGlobal(null)
                ->setCostCenter(null)
                ->setBilling(null),
        );
    }

    public function testGetterSetter(): void
    {
        $project = new Project();

        // test id
        self::assertNull($project->getId());
        $project->setId(17);
        self::assertSame(17, $project->getId());

        // test name
        self::assertSame('', $project->getName());
        $project->setName('Test-Project');
        self::assertSame('Test-Project', $project->getName());

        // test ticket prefix
        self::assertNull($project->getJiraId());
        $project->setJiraId('ABC');
        self::assertSame('ABC', $project->getJiraId());

        // test active
        self::assertFalse($project->getActive());
        $project->setActive(true);
        self::assertTrue($project->getActive());

        // test global
        self::assertFalse($project->getGlobal());
        $project->setGlobal(true);
        self::assertTrue($project->getGlobal());

        // test estimation
        self::assertNull($project->getEstimation());
        $project->setEstimation(120);
        self::assertSame(120, $project->getEstimation());

        // test offer
        self::assertNull($project->getOffer());
        $project->setOffer('12-UF9182-4');
        self::assertSame('12-UF9182-4', $project->getOffer());

        // test cost center
        self::assertNull($project->getCostCenter());
        $project->setCostCenter('12345');
        self::assertSame('12345', $project->getCostCenter());

        // test billing
        self::assertSame(0, $project->getBilling());
        $project->setBilling(Project::BILLING_TM);
        self::assertSame(Project::BILLING_TM, $project->getBilling());

        // test invoice
        self::assertNull($project->getInvoice());
        $project->setInvoice('20130122456');
        self::assertSame('20130122456', $project->getInvoice());

        // test ticket system
        self::assertNull($project->getTicketSystem());
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        self::assertSame($ticketSystem, $project->getTicketSystem());
        $project->setTicketSystem(null);
        self::assertNull($project->getTicketSystem());

        // test project and technical lead
        self::assertNull($project->getProjectLead());
        self::assertNull($project->getTechnicalLead());
        $projectLead = new User();
        $project->setProjectLead($projectLead);
        self::assertSame($projectLead, $project->getProjectLead());
        $technicalLead = new User();
        $project->setTechnicalLead($technicalLead);
        self::assertSame($technicalLead, $project->getTechnicalLead());
        $project->setProjectLead(null);
        self::assertNull($project->getProjectLead());
        $project->setTechnicalLead(null);
        self::assertNull($project->getTechnicalLead());
    }
}
