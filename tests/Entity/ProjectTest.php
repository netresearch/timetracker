<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\BillingType;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class ProjectTest extends TestCase
{
    public function testGetterSetter(): void
    {
        $project = new Project();

        // test name
        self::assertSame('', $project->getName());
        $project->setName('foobar project');
        self::assertSame('foobar project', $project->getName());

        // test active
        self::assertFalse($project->getActive());
        $project->setActive(false);
        self::assertFalse($project->getActive());

        // test global
        self::assertFalse($project->getGlobal());
        $project->setGlobal(true);
        self::assertTrue($project->getGlobal());

        // test offer
        self::assertNull($project->getOffer());
        $project->setOffer('20130322002_test_4711');
        self::assertSame('20130322002_test_4711', $project->getOffer());

        // test additional information from external ticket system
        self::assertFalse($project->getAdditionalInformationFromExternal());
        $project->setAdditionalInformationFromExternal(true);
        self::assertTrue($project->getAdditionalInformationFromExternal());

        // test jira id
        self::assertNull($project->getJiraId());
        $project->setJiraId('TEST');
        self::assertSame('TEST', $project->getJiraId());

        // Note: progress field was removed as it doesn't exist in the database

        // test project lead user
        self::assertNull($project->getProjectLead());
        $projectLead = new User();
        $projectLead->setId(14);
        $project->setProjectLead($projectLead);
        self::assertSame($projectLead, $project->getProjectLead());

        // test technical lead user
        self::assertNull($project->getTechnicalLead());
        $technicalLead = new User();
        $technicalLead->setId(15);
        $project->setTechnicalLead($technicalLead);
        self::assertSame($technicalLead, $project->getTechnicalLead());

        // test estimation
        self::assertSame(0, $project->getEstimation());
        $project->setEstimation(2500);
        self::assertSame(2500, $project->getEstimation());

        // test cost center
        self::assertNull($project->getCostCenter());
        $project->setCostCenter('12345');
        self::assertSame('12345', $project->getCostCenter());

        // test billing
        self::assertSame(BillingType::NONE, $project->getBilling());
        $project->setBilling(BillingType::TIME_AND_MATERIAL);
        self::assertSame(BillingType::TIME_AND_MATERIAL, $project->getBilling());

        // test invoice
        self::assertNull($project->getInvoice());
        $project->setInvoice('20130122456');
        self::assertSame('20130122456', $project->getInvoice());

        // test ticket system
        self::assertNull($project->getTicketSystem());
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        self::assertSame($ticketSystem, $project->getTicketSystem());
    }
}
