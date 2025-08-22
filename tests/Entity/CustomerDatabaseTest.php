<?php

namespace Tests\Entity;

use Tests\AbstractWebTestCase;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\Team;
use Doctrine\ORM\EntityManagerInterface;

class CustomerDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        // Create a new Customer
        $customer = new Customer();
        $customer->setName('Test Database Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);

        // Persist to database
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $customer->getId();
        $this->assertNotNull($id, 'Customer ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedCustomer = $this->entityManager->getRepository(Customer::class)->find($id);
        $this->assertNotNull($fetchedCustomer, 'Customer was not found in database');
        $this->assertEquals('Test Database Customer', $fetchedCustomer->getName());
        $this->assertTrue($fetchedCustomer->getActive());
        $this->assertFalse($fetchedCustomer->getGlobal());

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        // Create a new Customer
        $customer = new Customer();
        $customer->setName('Customer To Update');
        $customer->setActive(true);
        $customer->setGlobal(false);

        // Persist to database
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $id = $customer->getId();

        // Update customer
        $customer->setName('Updated Customer');
        $customer->setActive(false);
        $customer->setGlobal(true);

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedCustomer = $this->entityManager->getRepository(Customer::class)->find($id);
        $this->assertEquals('Updated Customer', $updatedCustomer->getName());
        $this->assertFalse($updatedCustomer->getActive());
        $this->assertTrue($updatedCustomer->getGlobal());

        // Clean up
        $this->entityManager->remove($updatedCustomer);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        // Create a new Customer
        $customer = new Customer();
        $customer->setName('Customer To Delete');
        $customer->setActive(true);
        $customer->setGlobal(false);

        // Persist to database
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $id = $customer->getId();

        // Delete customer
        $this->entityManager->remove($customer);
        $this->entityManager->flush();

        // Verify customer is deleted
        $deletedCustomer = $this->entityManager->getRepository(Customer::class)->find($id);
        $this->assertNull($deletedCustomer, 'Customer should be deleted from database');
    }

    public function testProjectRelationship(): void
    {
        // Create and persist customer
        $customer = new Customer();
        $customer->setName('Customer With Projects');
        $customer->setActive(true);
        $customer->setGlobal(false);

        $this->entityManager->persist($customer);

        // Create and add projects
        $project1 = new Project();
        $project1->setName('Project 1');
        $project1->setActive(true);
        $project1->setGlobal(false);
        $project1->setCustomer($customer);
        $project1->setOffer('');
        $project1->setBilling(0);
        $project1->setEstimation(0);
        $project1->setAdditionalInformationFromExternal(false);

        $project2 = new Project();
        $project2->setName('Project 2');
        $project2->setActive(true);
        $project2->setGlobal(false);
        $project2->setCustomer($customer);
        $project2->setOffer('');
        $project2->setBilling(0);
        $project2->setEstimation(0);
        $project2->setAdditionalInformationFromExternal(false);

        $this->entityManager->persist($project1);
        $this->entityManager->persist($project2);
        $this->entityManager->flush();

        $customerId = $customer->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);

        // Test project relationship
        $this->assertCount(2, $fetchedCustomer->getProjects());

        // Clean up
        $projects = $fetchedCustomer->getProjects();
        foreach ($projects as $project) {
            $this->entityManager->remove($project);
        }

        $this->entityManager->flush();
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
    }

    public function testTeamRelationship(): void
    {
        // Create and persist customer
        $customer = new Customer();
        $customer->setName('Customer With Teams');
        $customer->setActive(true);
        $customer->setGlobal(false);

        $this->entityManager->persist($customer);

        // Create and add teams
        $team1 = new Team();
        $team1->setName('Team 1');

        $this->entityManager->persist($team1);

        $team2 = new Team();
        $team2->setName('Team 2');

        $this->entityManager->persist($team2);

        // Establish the bidirectional relationship
        $customer->addTeam($team1);
        $customer->addTeam($team2);

        $team1->addCustomer($customer);
        $team2->addCustomer($customer);

        $this->entityManager->flush();
        $customerId = $customer->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedCustomer = $this->entityManager->find(Customer::class, $customerId);

        // Test team relationship
        $this->assertCount(2, $fetchedCustomer->getTeams());

        // Clean up
        $teams = $fetchedCustomer->getTeams()->toArray(); // Convert to array to avoid modification during iteration
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();

        foreach ($teams as $team) {
            $this->entityManager->remove($team);
        }

        $this->entityManager->flush();
    }

    public function testQueryMethodsInRepository(): void
    {
        // Create test customers
        $customer1 = new Customer();
        $customer1->setName('Customer1');
        $customer1->setActive(true);
        $customer1->setGlobal(false);

        $customer2 = new Customer();
        $customer2->setName('Customer2');
        $customer2->setActive(false);
        $customer2->setGlobal(true);

        // Persist to database
        $this->entityManager->persist($customer1);
        $this->entityManager->persist($customer2);
        $this->entityManager->flush();

        // Test repository methods
        $entityRepository = $this->entityManager->getRepository(Customer::class);

        // Test findAll
        $allCustomers = $entityRepository->findAll();
        $this->assertGreaterThanOrEqual(2, count($allCustomers));

        // Test findBy with criteria
        $activeCustomers = $entityRepository->findBy(['active' => true]);
        $this->assertGreaterThanOrEqual(1, count($activeCustomers));

        $globalCustomers = $entityRepository->findBy(['global' => true]);
        $this->assertGreaterThanOrEqual(1, count($globalCustomers));

        // Clean up
        $this->entityManager->remove($customer1);
        $this->entityManager->remove($customer2);
        $this->entityManager->flush();
    }
}
