<?php

namespace Tests\Entity;

use Tests\AbstractWebTestCase;
use App\Entity\Project;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\TicketSystem;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ProjectDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        // Create a customer for the project
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // Create a new Project
        $project = new Project();
        $project->setName('Test Database Project');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('TEST-OFFER');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(120);
        $project->setAdditionalInformationFromExternal(false);

        // Persist to database
        $this->entityManager->persist($project);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $project->getId();
        $customerId = $customer->getId();
        $this->assertNotNull($id, 'Project ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedProject = $this->entityManager->getRepository(Project::class)->find($id);
        $this->assertNotNull($fetchedProject, 'Project was not found in database');
        $this->assertEquals('Test Database Project', $fetchedProject->getName());
        $this->assertTrue($fetchedProject->getActive());
        $this->assertFalse($fetchedProject->getGlobal());
        $this->assertEquals('TEST-OFFER', $fetchedProject->getOffer());
        $this->assertEquals(Project::BILLING_TM, $fetchedProject->getBilling());
        $this->assertEquals(120, $fetchedProject->getEstimation());

        // Check customer relationship
        $this->assertNotNull($fetchedProject->getCustomer());
        $this->assertEquals('Test Customer', $fetchedProject->getCustomer()->getName());

        // Clean up - remove the test entities
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->flush();

        // Re-fetch customer to ensure it's managed
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
            $this->entityManager->flush();
        }
    }

    public function testUpdate(): void
    {
        // Create a customer for the project
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // Create a new Project
        $project = new Project();
        $project->setName('Project To Update');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-ORIG');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);

        // Persist to database
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $id = $project->getId();
        $customerId = $customer->getId();

        // Update project
        $project->setName('Updated Project');
        $project->setActive(false);
        $project->setGlobal(true);
        $project->setOffer('OFFER-UPDATED');
        $project->setBilling(Project::BILLING_FP);
        $project->setEstimation(200);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedProject = $this->entityManager->getRepository(Project::class)->find($id);
        $this->assertEquals('Updated Project', $updatedProject->getName());
        $this->assertFalse($updatedProject->getActive());
        $this->assertTrue($updatedProject->getGlobal());
        $this->assertEquals('OFFER-UPDATED', $updatedProject->getOffer());
        $this->assertEquals(Project::BILLING_FP, $updatedProject->getBilling());
        $this->assertEquals(200, $updatedProject->getEstimation());

        // Clean up
        $this->entityManager->remove($updatedProject);
        $this->entityManager->flush();

        // Re-fetch customer to ensure it's managed
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
            $this->entityManager->flush();
        }
    }

    public function testDelete(): void
    {
        // Create a customer for the project
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // Create a new Project
        $project = new Project();
        $project->setName('Project To Delete');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-DELETE');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);

        // Persist to database
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $id = $project->getId();
        $customerId = $customer->getId();

        // Clear EntityManager to simulate a fresh state
        $this->entityManager->clear();

        // Re-fetch the project to ensure it's managed
        $projectToDelete = $this->entityManager->find(Project::class, $id);
        $this->assertNotNull($projectToDelete, 'Project should exist before deletion');

        // Delete project
        $this->entityManager->remove($projectToDelete);
        $this->entityManager->flush();

        // Verify project is deleted
        $deletedProject = $this->entityManager->getRepository(Project::class)->find($id);
        $this->assertNull($deletedProject, 'Project should be deleted from database');

        // Clean up customer - re-fetch to ensure it's managed
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
            $this->entityManager->flush();
        }
    }

    public function testEntryRelationship(): void
    {
        // Create prerequisites - customer
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // Create and persist project
        $project = new Project();
        $project->setName('Project With Entries');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-ENTRIES');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);
        $this->entityManager->persist($project);

        // Create and add entries
        $entry1 = new Entry();
        $entry1->setProject($project);
        $entry1->setDay('2023-01-01');
        $entry1->setStart('09:00');
        $entry1->setEnd('10:00');
        $entry1->setDuration(60);
        $entry1->setTicket('TEST-001');
        $entry1->setDescription('Test entry 1');
        $entry1->setClass(Entry::CLASS_PLAIN);

        $entry2 = new Entry();
        $entry2->setProject($project);
        $entry2->setDay('2023-01-02');
        $entry2->setStart('14:00');
        $entry2->setEnd('16:00');
        $entry2->setDuration(120);
        $entry2->setTicket('TEST-002');
        $entry2->setDescription('Test entry 2');
        $entry2->setClass(Entry::CLASS_PLAIN);

        $this->entityManager->persist($entry1);
        $this->entityManager->persist($entry2);
        $this->entityManager->flush();
        $projectId = $project->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedProject = $this->entityManager->find(Project::class, $projectId);

        // Test entry relationship
        $this->assertCount(2, $fetchedProject->getEntries());

        // Clean up
        $entries = $fetchedProject->getEntries();
        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();

        $this->entityManager->remove($fetchedProject);
        $this->entityManager->flush();

        $customer = $this->entityManager->find(Customer::class, $customer->getId());
        $this->entityManager->remove($customer);
        $this->entityManager->flush();
    }

    public function testLeadRelationships(): void
    {
        // Create prerequisites - customer and users
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        $projectLead = new User();
        $projectLead->setUsername('project_lead');
        $projectLead->setType('PL');
        $projectLead->setLocale('de');
        $this->entityManager->persist($projectLead);

        $technicalLead = new User();
        $technicalLead->setUsername('technical_lead');
        $technicalLead->setType('DEV');
        $technicalLead->setLocale('de');
        $this->entityManager->persist($technicalLead);

        // Create project with leads
        $project = new Project();
        $project->setName('Project With Leads');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-LEADS');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);
        $project->setProjectLead($projectLead);
        $project->setTechnicalLead($technicalLead);

        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $projectId = $project->getId();
        $customerId = $customer->getId();
        $projectLeadId = $projectLead->getId();
        $technicalLeadId = $technicalLead->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedProject = $this->entityManager->find(Project::class, $projectId);

        // Test relationships
        $this->assertNotNull($fetchedProject->getProjectLead());
        $this->assertEquals('project_lead', $fetchedProject->getProjectLead()->getUsername());

        $this->assertNotNull($fetchedProject->getTechnicalLead());
        $this->assertEquals('technical_lead', $fetchedProject->getTechnicalLead()->getUsername());

        // Clean up - remove the re-fetched project first
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->flush();

        // Re-fetch and remove the other entities
        $fetchedProjectLead = $this->entityManager->find(User::class, $projectLeadId);
        $fetchedTechnicalLead = $this->entityManager->find(User::class, $technicalLeadId);
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);

        if ($fetchedProjectLead) {
            $this->entityManager->remove($fetchedProjectLead);
        }
        if ($fetchedTechnicalLead) {
            $this->entityManager->remove($fetchedTechnicalLead);
        }
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
        }
        $this->entityManager->flush();
    }

    public function testTicketSystemRelationship(): void
    {
        // Create prerequisites
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        $ticketSystem = new TicketSystem();
        $ticketSystem->setName('Test Ticket System');
        $ticketSystem->setType('jira');
        $ticketSystem->setBookTime(true);
        $ticketSystem->setUrl('https://jira.example.com');
        $ticketSystem->setLogin('test_login');
        $ticketSystem->setPassword('test_password');
        $ticketSystem->setTicketurl('https://jira.example.com/ticket/{ticket}');
        $this->entityManager->persist($ticketSystem);

        // Create project with ticket system
        $project = new Project();
        $project->setName('Project With Ticket System');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-TICKET');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);
        $project->setTicketSystem($ticketSystem);
        $project->setJiraId('TEST');

        $this->entityManager->persist($project);
        $this->entityManager->flush();
        $projectId = $project->getId();
        $customerId = $customer->getId();
        $ticketSystemId = $ticketSystem->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedProject = $this->entityManager->find(Project::class, $projectId);

        // Test ticket system relationship
        $this->assertNotNull($fetchedProject->getTicketSystem());
        $this->assertEquals('Test Ticket System', $fetchedProject->getTicketSystem()->getName());
        $this->assertEquals('TEST', $fetchedProject->getJiraId());

        // Clean up - remove the re-fetched project first
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->flush();

        // Re-fetch and remove ticket system and customer
        $fetchedTicketSystem = $this->entityManager->find(TicketSystem::class, $ticketSystemId);
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);

        if ($fetchedTicketSystem) {
            $this->entityManager->remove($fetchedTicketSystem);
        }
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
        }
        $this->entityManager->flush();
    }

    public function testPresetRelationship(): void
    {
        // Create prerequisites
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // Create activity for presets
        $activity = new \App\Entity\Activity();
        $activity->setName('Test Activity');
        $activity->setNeedsTicket(true);
        $activity->setFactor(1.0);
        $this->entityManager->persist($activity);

        // Create project
        $project = new Project();
        $project->setName('Project With Presets');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-PRESETS');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);
        $this->entityManager->persist($project);

        // Create presets
        $preset1 = new Preset();
        $preset1->setName('Preset 1');
        $preset1->setProject($project);
        $preset1->setCustomer($customer);
        $preset1->setActivity($activity);
        $preset1->setDescription('Test Preset 1');

        $preset2 = new Preset();
        $preset2->setName('Preset 2');
        $preset2->setProject($project);
        $preset2->setCustomer($customer);
        $preset2->setActivity($activity);
        $preset2->setDescription('Test Preset 2');

        $this->entityManager->persist($preset1);
        $this->entityManager->persist($preset2);
        $this->entityManager->flush();
        $projectId = $project->getId();
        $customerId = $customer->getId();
        $activityId = $activity->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedProject = $this->entityManager->find(Project::class, $projectId);

        // Test presets relationship
        $this->assertCount(2, $fetchedProject->getPresets());

        // Clean up presets first
        foreach ($fetchedProject->getPresets() as $preset) {
            $this->entityManager->remove($preset);
        }
        $this->entityManager->flush();

        // Clean up project
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->flush();

        // Re-fetch and remove activity and customer
        $fetchedActivity = $this->entityManager->find(\App\Entity\Activity::class, $activityId);
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);

        if ($fetchedActivity) {
            $this->entityManager->remove($fetchedActivity);
        }
        if ($fetchedCustomer) {
            $this->entityManager->remove($fetchedCustomer);
        }
        $this->entityManager->flush();
    }
}
