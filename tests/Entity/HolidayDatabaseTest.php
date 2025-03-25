<?php

namespace Tests\Entity;

use Tests\Base;
use App\Entity\Holiday;
use Doctrine\ORM\EntityManagerInterface;

class HolidayDatabaseTest extends Base
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        $this->markTestSkipped('Writing public holidays to database was never fully implemented');
        // Create a new Holiday
        $day = new \DateTime('2023-12-25');
        $holiday = new Holiday($day, 'Christmas');

        // Persist to database
        $this->entityManager->persist($holiday);
        $this->entityManager->flush();

        // Clear entity manager to ensure fetch from DB
        $this->entityManager->clear();

        // Fetch from database and verify
        $fetchedHoliday = $this->entityManager->getRepository(Holiday::class)->find($day);
        $this->assertNotNull($fetchedHoliday, 'Holiday was not found in database');
        $this->assertEquals('Christmas', $fetchedHoliday->getName());
        $this->assertEquals($day->format('Y-m-d'), $fetchedHoliday->getDay()->format('Y-m-d'));

        // Clean up - remove the test entity
        $this->entityManager->remove($fetchedHoliday);
        $this->entityManager->flush();
    }

    public function testUpdate(): void
    {
        $this->markTestSkipped('Writing public holidays to database was never fully implemented');
        // Create a new Holiday
        $day = new \DateTime('2023-01-01');
        $holiday = new Holiday($day, 'New Year');

        // Persist to database
        $this->entityManager->persist($holiday);
        $this->entityManager->flush();

        // Update holiday
        $holiday->setName('Updated Holiday');
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch and verify updates
        $updatedHoliday = $this->entityManager->getRepository(Holiday::class)->find($day);
        $this->assertEquals('Updated Holiday', $updatedHoliday->getName());

        // Clean up
        $this->entityManager->remove($updatedHoliday);
        $this->entityManager->flush();
    }

    public function testDelete(): void
    {
        $this->markTestSkipped('Writing public holidays to database was never fully implemented');
        // Create a new Holiday
        $day = new \DateTime('2023-05-01');
        $holiday = new Holiday($day, 'Labor Day');

        // Persist to database
        $this->entityManager->persist($holiday);
        $this->entityManager->flush();

        // Delete holiday
        $this->entityManager->remove($holiday);
        $this->entityManager->flush();

        // Verify holiday is deleted
        $deletedHoliday = $this->entityManager->getRepository(Holiday::class)->find($day);
        $this->assertNull($deletedHoliday, 'Holiday should be deleted from database');
    }

    public function testFindByYear(): void
    {
        $this->markTestSkipped('Writing public holidays to database was never fully implemented');
        // Create holidays for specific years
        $holiday2022 = new Holiday(new \DateTime('2022-12-25'), 'Christmas 2022');
        $holiday2023a = new Holiday(new \DateTime('2023-01-01'), 'New Year 2023');
        $holiday2023b = new Holiday(new \DateTime('2023-12-25'), 'Christmas 2023');

        // Persist to database
        $this->entityManager->persist($holiday2022);
        $this->entityManager->persist($holiday2023a);
        $this->entityManager->persist($holiday2023b);
        $this->entityManager->flush();

        // Query holidays for 2023
        $holidays2023 = $this->entityManager->createQueryBuilder()
            ->select('h')
            ->from(Holiday::class, 'h')
            ->where('h.day >= :start')
            ->andWhere('h.day <= :end')
            ->setParameter('start', new \DateTime('2023-01-01'))
            ->setParameter('end', new \DateTime('2023-12-31'))
            ->getQuery()
            ->getResult();

        // Test repository query
        $this->assertCount(2, $holidays2023);

        // Cleanup
        $this->entityManager->remove($holiday2022);
        $this->entityManager->remove($holiday2023a);
        $this->entityManager->remove($holiday2023b);
        $this->entityManager->flush();
    }

    public function testToArray(): void
    {
        // Create a new Holiday
        $day = new \DateTime('2023-12-25');
        $holiday = new Holiday($day, 'Christmas');

        // Test toArray() method
        $array = $holiday->toArray();
        $this->assertEquals('25/12/2023', $array['day']);
        $this->assertEquals('Christmas', $array['description']);

        // No need to persist for this test
    }
}
