<?php

namespace Netresearch\TimeTrackerBundle\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\TicketSystem;

class ProjectTest extends TestCase
{
    public function testFluentInterface()
    {
        $project = new Project();

        $this->assertEquals($project, 
            $project
                ->setId(null)
                ->setName(null)
                ->setJiraId(null)
                ->setGlobal(null)
                ->setEstimation(null)
                ->setOffer(null)
                ->setGlobal(null)
                ->setCostCenter(null)
                ->setBilling(null)
        );
    }

    public function testGetterSetter()
    {
        $project = new Project();

        // test id
        $this->assertEquals(null, $project->getId());
        $project->setId(17);
        $this->assertEquals(17, $project->getId());

        // test name
        $this->assertEquals(null, $project->getName());
        $project->setName('Test-Project');
        $this->assertEquals('Test-Project', $project->getName());

        // test ticket prefix 
        $this->assertEquals(null, $project->getJiraId());
        $project->setJiraId('ABC');
        $this->assertEquals('ABC', $project->getJiraId());

        // test active
        $this->assertEquals(null, $project->getActive());
        $project->setActive(true);
        $this->assertEquals(true, $project->getActive());

        // test global
        $this->assertEquals(null, $project->getGlobal());
        $project->setGlobal(true);
        $this->assertEquals(true, $project->getGlobal());

        // test estimation
        $this->assertEquals(null, $project->getEstimation());
        $project->setEstimation(120);
        $this->assertEquals(120, $project->getEstimation());

        // test offer
        $this->assertEquals(null, $project->getOffer());
        $project->setOffer('12-UF9182-4');
        $this->assertEquals('12-UF9182-4', $project->getOffer());

        // test cost center
        $this->assertEquals(null, $project->getCostCenter());
        $project->setCostCenter('12345');
        $this->assertEquals('12345', $project->getCostCenter());

        // test billing
        $this->assertEquals(null, $project->getBilling());
        $project->setBilling(Project::BILLING_TM);
        $this->assertEquals(Project::BILLING_TM, $project->getBilling());

        // test invoice 
        $this->assertEquals(null, $project->getInvoice());
        $project->setInvoice('20130122456');
        $this->assertEquals('20130122456', $project->getInvoice());

        // test ticket system
        $this->assertEquals(null, $project->getTicketSystem());
        $ticketSystem = new TicketSystem();
        $project->setTicketSystem($ticketSystem);
        $this->assertEquals($ticketSystem, $project->getTicketSystem());
        $project->setTicketSystem(null);
        $this->assertEquals(null, $project->getTicketSystem());

        // test project and technical lead
        $this->assertEquals(null, $project->getProjectLead());
        $this->assertEquals(null, $project->getTechnicalLead());
        $projectLead = new User();
        $project->setProjectLead($projectLead);
        $this->assertEquals($projectLead, $project->getProjectLead());
        $technicalLead = new User();
        $project->setTechnicalLead($technicalLead);
        $this->assertEquals($technicalLead, $project->getTechnicalLead());
        $project->setProjectLead(null);
        $this->assertEquals(null, $project->getProjectLead());
        $project->setTechnicalLead(null);
        $this->assertEquals(null, $project->getTechnicalLead());
    }
}

