<?php declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\Project;
use App\Entity\User;
use App\Entity\Customer;
use App\Entity\TicketSystem;

class ProjectTest extends TestCase
{
    public function testFluentInterface(): void
    {
        $project = new Project();

        static::assertSame(
            $project,
            $project
                ->setId(1)
                ->setName('project')
                ->setJiraId(null)
                ->setGlobal(true)
                ->setEstimation(null)
                ->setOffer(null)
                ->setGlobal(false)
                ->setCostCenter(null)
                ->setBilling(null)
        );
    }

    public function testGetterSetter(): void
    {
        $project = new Project();

        // test id
        static::assertNull($project->getId());
        $project->setId(17);
        static::assertSame(17, $project->getId());

        // test name
        static::assertEmpty($project->getName());
        $project->setName('Test-Project');
        static::assertSame('Test-Project', $project->getName());

        // test ticket prefix 
        static::assertEmpty($project->getJiraId());
        $project->setJiraId('ABC');
        static::assertSame('ABC', $project->getJiraId());

        // test active
        static::assertTrue($project->getActive());
        $project->setActive(false);
        static::assertFalse($project->getActive());

        // test global
        static::assertTrue($project->getGlobal());
        $project->setGlobal(false);
        static::assertFalse($project->getGlobal());

        // test estimation
        static::assertSame(0, $project->getEstimation());
        $project->setEstimation(120);
        static::assertSame(120, $project->getEstimation());

        // test offer
        static::assertEmpty($project->getOffer());
        $project->setOffer('12-UF9182-4');
        static::assertSame('12-UF9182-4', $project->getOffer());

        // test cost center
        static::assertEmpty($project->getCostCenter());
        $project->setCostCenter('12345');
        static::assertSame('12345', $project->getCostCenter());

        // test billing
        static::assertSame(Project::BILLING_NONE, $project->getBilling());
        $project->setBilling(Project::BILLING_TM);
        static::assertSame(Project::BILLING_TM, $project->getBilling());

        // test invoice 
        static::assertEmpty($project->getInvoice());
        $project->setInvoice('20130122456');
        static::assertSame('20130122456', $project->getInvoice());

        // test ticket system
        static::assertNull($project->getTicketSystem());
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        static::assertSame($ticketSystem, $project->getTicketSystem());
        $project->setTicketSystem(null);
        static::assertNull($project->getTicketSystem());

        // test project and technical lead
        static::assertNull($project->getProjectLead());
        static::assertNull($project->getTechnicalLead());
        $projectLead = new User();
        $project->setProjectLead($projectLead);
        static::assertSame($projectLead, $project->getProjectLead());
        $technicalLead = new User();
        $project->setTechnicalLead($technicalLead);
        static::assertSame($technicalLead, $project->getTechnicalLead());
        $project->setProjectLead(null);
        static::assertNull($project->getProjectLead());
        $project->setTechnicalLead(null);
        static::assertNull($project->getTechnicalLead());
    }
}

