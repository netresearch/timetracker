<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Preset;
use App\Entity\Project;
use App\Enum\BillingType;
use Doctrine\ORM\EntityManagerInterface;
use Tests\AbstractWebTestCase;

/**
 * @internal
 *
 * @coversNothing
 */
final class PresetDatabaseTest extends AbstractWebTestCase
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
        self::assertNotNull($id, 'Preset ID should not be null after persist');
        $this->entityManager->clear();

        // Re-fetch the preset to ensure it's managed
        $fetchedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        self::assertNotNull($fetchedPreset, 'Preset was not found in database');
        self::assertSame('Test Database Preset', $fetchedPreset->getName());
        self::assertSame('Test Description', $fetchedPreset->getDescription());

        // Test relationships
        self::assertNotNull($fetchedPreset->getCustomer());
        self::assertSame($customer->getId(), $fetchedPreset->getCustomerId());

        self::assertNotNull($fetchedPreset->getProject());
        self::assertSame($project->getId(), $fetchedPreset->getProjectId());

        self::assertNotNull($fetchedPreset->getActivity());
        self::assertSame($activity->getId(), $fetchedPreset->getActivityId());

        // Clean up - re-fetch related entities to ensure they are managed
        $fetchedActivity = $this->entityManager->getRepository(Activity::class)->find($activity->getId());
        $fetchedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $fetchedCustomer = $this->entityManager->getRepository(Customer::class)->find($customer->getId());

        // Remove the test entities
        $this->entityManager->remove($fetchedPreset);
        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
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

        // Re-fetch the preset to ensure it's managed
        $updatedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        self::assertSame('Updated Preset', $updatedPreset->getName());
        self::assertSame('Updated Description', $updatedPreset->getDescription());

        // Clean up - re-fetch related entities to ensure they are managed
        $fetchedActivity = $this->entityManager->getRepository(Activity::class)->find($activity->getId());
        $fetchedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $fetchedCustomer = $this->entityManager->getRepository(Customer::class)->find($customer->getId());

        // Remove the test entities
        $this->entityManager->remove($updatedPreset);
        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
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

        // Clear the EntityManager to simulate a fresh state
        $this->entityManager->clear();

        // Re-fetch the preset to ensure it's managed
        $presetToDelete = $this->entityManager->getRepository(Preset::class)->find($id);
        self::assertNotNull($presetToDelete, 'Preset should exist before deletion');

        // Remove the test entities
        $this->entityManager->remove($presetToDelete);
        $this->entityManager->flush();

        // Verify preset is deleted
        $deletedPreset = $this->entityManager->getRepository(Preset::class)->find($id);
        self::assertNull($deletedPreset, 'Preset should be deleted from database');

        // Clean up remaining entities
        $fetchedActivity = $this->entityManager->getRepository(Activity::class)->find($activity->getId());
        $fetchedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $fetchedCustomer = $this->entityManager->getRepository(Customer::class)->find($customer->getId());

        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
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
        self::assertSame($preset->getId(), $array['id']);
        self::assertSame('Array Test Preset', $array['name']);
        self::assertSame('Test Description', $array['description']);
        self::assertSame($customer->getId(), $array['customer']);
        self::assertSame($project->getId(), $array['project']);
        self::assertSame($activity->getId(), $array['activity']);

        // Clear the EntityManager to simulate a fresh state
        $this->entityManager->clear();

        // Re-fetch the entities to ensure they are managed
        $fetchedPreset = $this->entityManager->getRepository(Preset::class)->find($preset->getId());
        $fetchedActivity = $this->entityManager->getRepository(Activity::class)->find($activity->getId());
        $fetchedProject = $this->entityManager->getRepository(Project::class)->find($project->getId());
        $fetchedCustomer = $this->entityManager->getRepository(Customer::class)->find($customer->getId());

        // Clean up
        $this->entityManager->remove($fetchedPreset);
        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->remove($fetchedProject);
        $this->entityManager->remove($fetchedCustomer);
        $this->entityManager->flush();
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
        $project->setBilling(BillingType::TIME_AND_MATERIAL);
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
}
