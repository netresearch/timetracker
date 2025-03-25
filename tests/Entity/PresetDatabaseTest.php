<?php

namespace Tests\Entity;

use Tests\Base;
use App\Entity\Preset;
use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Activity;
use Doctrine\ORM\EntityManagerInterface;

class PresetDatabaseTest extends Base
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        // Create prerequisites
        $customer = $this->createCustomer();
        $project = $this->createProject($customer);
        $activity = $this->createActivity();

        // Create a new Preset
        $preset = new Preset();
        $preset->setName('Test Database Preset');
        $preset->setDescription('Test Description');
        $preset->setCustomer($customer);
        $preset->setProject($project);
        $preset->setActivity($activity);

        // Persist to database
        $this->entityManager->persist($preset);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $preset->getId();
        $this->assertNotNull($id, 'Preset ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        $this->assertNotNull($fetchedPreset, 'Preset was not found in database');
        $this->assertEquals('Test Database Preset', $fetchedPreset->getName());
        $this->assertEquals('Test Description', $fetchedPreset->getDescription());

        // Test relationships
        $this->assertNotNull($fetchedPreset->getCustomer());
        $this->assertEquals($customer->getId(), $fetchedPreset->getCustomerId());

        $this->assertNotNull($fetchedPreset->getProject());
        $this->assertEquals($project->getId(), $fetchedPreset->getProjectId());

        $this->assertNotNull($fetchedPreset->getActivity());
        $this->assertEquals($activity->getId(), $fetchedPreset->getActivityId());

        // Clean up - remove the test entities
        $this->entityManager->remove($fetchedPreset);
        $this->entityManager->flush();

        $this->cleanupEntities($activity, $project, $customer);
    }

    public function testUpdate(): void
    {
        // Create prerequisites
        $customer = $this->createCustomer();
        $project = $this->createProject($customer);
        $activity = $this->createActivity();

        // Create a new Preset
        $preset = new Preset();
        $preset->setName('Preset To Update');
        $preset->setDescription('Original Description');
        $preset->setCustomer($customer);
        $preset->setProject($project);
        $preset->setActivity($activity);

        // Persist to database
        $this->entityManager->persist($preset);
        $this->entityManager->flush();
        $id = $preset->getId();

        // Update preset
        $preset->setName('Updated Preset');
        $preset->setDescription('Updated Description');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        $this->assertEquals('Updated Preset', $updatedPreset->getName());
        $this->assertEquals('Updated Description', $updatedPreset->getDescription());

        // Clean up
        $this->entityManager->remove($updatedPreset);
        $this->entityManager->flush();

        $this->cleanupEntities($activity, $project, $customer);
    }

    public function testDelete(): void
    {
        // Create prerequisites
        $customer = $this->createCustomer();
        $project = $this->createProject($customer);
        $activity = $this->createActivity();

        // Create a new Preset
        $preset = new Preset();
        $preset->setName('Preset To Delete');
        $preset->setDescription('Delete Description');
        $preset->setCustomer($customer);
        $preset->setProject($project);
        $preset->setActivity($activity);

        // Persist to database
        $this->entityManager->persist($preset);
        $this->entityManager->flush();
        $id = $preset->getId();

        // Delete preset
        $this->entityManager->remove($preset);
        $this->entityManager->flush();

        // Verify preset is deleted
        $deletedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        $this->assertNull($deletedPreset, 'Preset should be deleted from database');

        // Clean up remaining entities
        $this->cleanupEntities($activity, $project, $customer);
    }

    public function testToArray(): void
    {
        // Create prerequisites
        $customer = $this->createCustomer();
        $project = $this->createProject($customer);
        $activity = $this->createActivity();

        // Create a new Preset
        $preset = new Preset();
        $preset->setName('Array Test Preset');
        $preset->setDescription('Test Description');
        $preset->setCustomer($customer);
        $preset->setProject($project);
        $preset->setActivity($activity);

        // Persist to database to get ID assigned
        $this->entityManager->persist($preset);
        $this->entityManager->flush();

        // Test toArray() method
        $array = $preset->toArray();
        $this->assertEquals($preset->getId(), $array['id']);
        $this->assertEquals('Array Test Preset', $array['name']);
        $this->assertEquals('Test Description', $array['description']);
        $this->assertEquals($customer->getId(), $array['customer']);
        $this->assertEquals($project->getId(), $array['project']);
        $this->assertEquals($activity->getId(), $array['activity']);

        // Clean up
        $this->entityManager->remove($preset);
        $this->entityManager->flush();

        $this->cleanupEntities($activity, $project, $customer);
    }

    // Helper methods to create required related entities
    private function createCustomer(): Customer
    {
        $customer = new Customer();
        $customer->setName('Preset Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();
        return $customer;
    }

    private function createProject(Customer $customer): Project
    {
        $project = new Project();
        $project->setName('Preset Test Project');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('OFFER-PRESET');
        $project->setBilling(Project::BILLING_TM);
        $project->setEstimation(100);
        $project->setAdditionalInformationFromExternal(false);
        $this->entityManager->persist($project);
        $this->entityManager->flush();
        return $project;
    }

    private function createActivity(): Activity
    {
        $activity = new Activity();
        $activity->setName('Preset Test Activity');
        $activity->setNeedsTicket(true);
        $activity->setFactor(1.0);
        $this->entityManager->persist($activity);
        $this->entityManager->flush();
        return $activity;
    }

    private function cleanupEntities(Activity $activity, Project $project, Customer $customer): void
    {
        $this->entityManager->remove($activity);
        $this->entityManager->remove($project);
        $this->entityManager->remove($customer);
        $this->entityManager->flush();
    }
}
