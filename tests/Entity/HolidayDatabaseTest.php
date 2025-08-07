<?php

namespace Tests\Entity;

use Tests\AbstractWebTestCase;
use App\Entity\Holiday;
use Doctrine\ORM\EntityManagerInterface;

class HolidayDatabaseTest extends AbstractWebTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = $this->serviceContainer->get('doctrine.orm.entity_manager');
    }

    public function testPersistAndFind(): void
    {
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        $day = '2023-12-25';
        $conn->insert('holidays', ['day' => $day, 'name' => 'Christmas']);

        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        $this->assertNotEmpty($row);
        $this->assertSame('Christmas', $row['name']);

        // Clean up
        $conn->delete('holidays', ['day' => $day]);
    }

    public function testUpdate(): void
    {
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        $day = '2023-01-01';
        $conn->insert('holidays', ['day' => $day, 'name' => 'New Year']);
        $conn->update('holidays', ['name' => 'Updated Holiday'], ['day' => $day]);
        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        $this->assertSame('Updated Holiday', $row['name']);
        $conn->delete('holidays', ['day' => $day]);
    }

    public function testDelete(): void
    {
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        $day = '2023-05-01';
        $conn->insert('holidays', ['day' => $day, 'name' => 'Labor Day']);
        $conn->delete('holidays', ['day' => $day]);
        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        $this->assertFalse((bool) $row, 'Holiday should be deleted from database');
    }

    public function testFindByYear(): void
    {
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        $rows = [
            ['day' => '2022-12-25', 'name' => 'Christmas 2022'],
            ['day' => '2023-01-01', 'name' => 'New Year 2023'],
            ['day' => '2023-12-25', 'name' => 'Christmas 2023'],
        ];
        foreach ($rows as $r) { $conn->insert('holidays', $r); }

        $result = $conn->fetchAllAssociative(
            'SELECT * FROM holidays WHERE day BETWEEN ? AND ? ORDER BY day ASC',
            ['2023-01-01', '2023-12-31']
        );
        $this->assertCount(2, $result);

        foreach ($rows as $r) { $conn->delete('holidays', ['day' => $r['day']]); }
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
