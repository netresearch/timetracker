<?php

namespace Tests\Entity;

use Tests\Base;
use App\Entity\Activity;
use App\Entity\Entry;
use App\Entity\Preset;
use App\Entity\Customer;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class ActivityDatabaseTest extends Base
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        // Create a new Activity
        $activity = new Activity();
        $activity->setName('Test Database Activity');
        $activity->setNeedsTicket(true);
        $activity->setFactor(1.25);

        // Persist to database
        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        // Get ID and clear entity manager to ensure fetch from DB
        $id = $activity->getId();
        $this->assertNotNull($id, 'Activity ID should not be null after persist');
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedActivity = $this->entityManager->getRepository(Activity::class)->find($id);
        $this->assertNotNull($fetchedActivity, 'Activity was not found in database');
        $this->assertEquals('Test Database Activity', $fetchedActivity->getName());
        $this->assertTrue($fetchedActivity->getNeedsTicket());
        $this->assertEquals(1.25, $fetchedActivity->getFactor());

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        // Create a new Activity
        $activity = new Activity();
        $activity->setName('Activity To Update');
        $activity->setNeedsTicket(false);
        $activity->setFactor(1.0);

        // Persist to database
        $this->entityManager->persist($activity);
        $this->entityManager->flush();
        $id = $activity->getId();

        // Update activity
        $activity->setName('Updated Activity');
        $activity->setNeedsTicket(true);
        $activity->setFactor(2.0);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedActivity = $this->entityManager->getRepository(Activity::class)->find($id);
        $this->assertEquals('Updated Activity', $updatedActivity->getName());
        $this->assertTrue($updatedActivity->getNeedsTicket());
        $this->assertEquals(2.0, $updatedActivity->getFactor());

        // Clean up
        $this->entityManager->remove($updatedActivity);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        // Create a new Activity
        $activity = new Activity();
        $activity->setName('Activity To Delete');
        $activity->setNeedsTicket(false);
        $activity->setFactor(1.0);

        // Persist to database
        $this->entityManager->persist($activity);
        $this->entityManager->flush();
        $id = $activity->getId();

        // Delete activity
        $this->entityManager->remove($activity);
        $this->entityManager->flush();

        // Verify activity is deleted
        $deletedActivity = $this->entityManager->getRepository(Activity::class)->find($id);
        $this->assertNull($deletedActivity, 'Activity should be deleted from database');
    }

    public function testFindByName(): void
    {
        // Create activities with specific names
        $sickActivity = new Activity();
        $sickActivity->setName(Activity::SICK);
        $sickActivity->setNeedsTicket(false);
        $sickActivity->setFactor(1.0);

        $holidayActivity = new Activity();
        $holidayActivity->setName(Activity::HOLIDAY);
        $holidayActivity->setNeedsTicket(false);
        $holidayActivity->setFactor(1.0);

        // Persist to database
        $this->entityManager->persist($sickActivity);
        $this->entityManager->persist($holidayActivity);
        $this->entityManager->flush();

        // Find activities by name
        $repo = $this->entityManager->getRepository(Activity::class);
        $foundSick = $repo->findOneBy(['name' => Activity::SICK]);
        $foundHoliday = $repo->findOneBy(['name' => Activity::HOLIDAY]);

        // Verify activities found
        $this->assertNotNull($foundSick, 'Sick activity should be found');
        $this->assertNotNull($foundHoliday, 'Holiday activity should be found');
        $this->assertTrue($foundSick->isSick(), 'Activity should be identified as sick');
        $this->assertTrue($foundHoliday->isHoliday(), 'Activity should be identified as holiday');

        // Clean up
        $this->entityManager->remove($foundSick);
        $this->entityManager->remove($foundHoliday);
        $this->entityManager->flush();
    }

    public function testEntryRelationship(): void
    {
        // Create and persist activity
        $activity = new Activity();
        $activity->setName('Activity With Entries');
        $activity->setNeedsTicket(true);
        $activity->setFactor(1.0);
        $this->entityManager->persist($activity);

        // Create and add entries
        $entry1 = new Entry();
        $entry1->setActivity($activity);
        $entry1->setDay('2023-01-01');
        $entry1->setStart('09:00');
        $entry1->setEnd('10:00');
        $entry1->setDuration(60);
        $entry1->setTicket('TEST-001');
        $entry1->setDescription('Test entry 1');
        $entry1->setClass(Entry::CLASS_PLAIN);

        $entry2 = new Entry();
        $entry2->setActivity($activity);
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
        $activityId = $activity->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedActivity = $this->entityManager->find(Activity::class, $activityId);

        // Test entry relationship
        $this->assertCount(2, $fetchedActivity->getEntries());
        $entries = $fetchedActivity->getEntries();
        $entryIds = [];
        foreach ($entries as $entry) {
            $entryIds[] = $entry->getId();
        }

        // Clean up
        foreach ($entries as $entry) {
            $this->entityManager->remove($entry);
        }
        $this->entityManager->flush();
        $this->entityManager->remove($fetchedActivity);
        $this->entityManager->flush();
    }

    public function testPresetRelationship(): void
    {
        // Create and persist activity
        $activity = new Activity();
        $activity->setName('Activity With Presets');
        $activity->setNeedsTicket(true);
        $activity->setFactor(1.0);
        $this->entityManager->persist($activity);

        // We need a customer for the preset
        $customer = new Customer();
        $customer->setName('Test Customer');
        $customer->setActive(true);
        $customer->setGlobal(false);
        $this->entityManager->persist($customer);

        // We need a project for the preset
        $project = new Project();
        $project->setName('Test Project');
        $project->setActive(true);
        $project->setGlobal(false);
        $project->setCustomer($customer);
        $project->setOffer('');
        $project->setBilling(0);
        $project->setEstimation(0);
        $project->setAdditionalInformationFromExternal(false);
        $this->entityManager->persist($project);

        // Create and add presets
        $preset1 = new Preset();
        $preset1->setName('Preset 1');
        $preset1->setActivity($activity);
        $preset1->setCustomer($customer);
        $preset1->setProject($project);
        $preset1->setDescription('Test Preset 1');

        $preset2 = new Preset();
        $preset2->setName('Preset 2');
        $preset2->setActivity($activity);
        $preset2->setCustomer($customer);
        $preset2->setProject($project);
        $preset2->setDescription('Test Preset 2');

        $this->entityManager->persist($preset1);
        $this->entityManager->persist($preset2);
        $this->entityManager->flush();
        $activityId = $activity->getId();

        // Clear entity manager and fetch from database
        $this->entityManager->clear();
        $fetchedActivity = $this->entityManager->find(Activity::class, $activityId);

        // Test preset relationship
        $this->assertCount(2, $fetchedActivity->getPresets());
        $presets = $fetchedActivity->getPresets();
        $presetIds = [];
        foreach ($presets as $preset) {
            $presetIds[] = $preset->getId();
        }

        // Clean up
        foreach ($presets as $preset) {
            $this->entityManager->remove($preset);
        }
        $this->entityManager->flush();
        $this->entityManager->remove($fetchedActivity);

        // Clean up customer and project
        $projectId = $project->getId();
        $customerId = $customer->getId();
        $project = $this->entityManager->find(Project::class, $projectId);
        $customer = $this->entityManager->find(Customer::class, $customerId);
        if ($project) {
            $this->entityManager->remove($project);
            $this->entityManager->flush();
        }
        if ($customer) {
            $this->entityManager->remove($customer);
            $this->entityManager->flush();
        }
    }

    public function testQueryMethodsInRepository(): void
    {
        // Create test activities with different combinations
        $activity1 = new Activity();
        $activity1->setName('Activity1');
        $activity1->setNeedsTicket(true);
        $activity1->setFactor(1.0);

        $activity2 = new Activity();
        $activity2->setName('Activity2');
        $activity2->setNeedsTicket(false);
        $activity2->setFactor(1.5);

        // Persist to database
        $this->entityManager->persist($activity1);
        $this->entityManager->persist($activity2);
        $this->entityManager->flush();

        // Test repository methods
        $repository = $this->entityManager->getRepository(Activity::class);

        // Test findAll
        $allActivities = $repository->findAll();
        $this->assertGreaterThanOrEqual(2, count($allActivities));

        // Test findBy with criteria
        $ticketActivities = $repository->findBy(['needsTicket' => true]);
        $this->assertGreaterThanOrEqual(1, count($ticketActivities));

        // Clean up
        $this->entityManager->remove($activity1);
        $this->entityManager->remove($activity2);
        $this->entityManager->flush();
    }
}
