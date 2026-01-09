<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Holiday;
use DateTime;
use RuntimeException;
use Tests\AbstractWebTestCase;

use function assert;

/**
 * @internal
 *
 * @coversNothing
 */
final class HolidayDatabaseTest extends AbstractWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testPersistAndFind(): void
    {
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        assert($conn instanceof \Doctrine\DBAL\Connection);
        $day = '2023-12-25';
        $conn->insert('holidays', ['day' => $day, 'name' => 'Christmas']);

        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        self::assertIsArray($row);
        self::assertNotEmpty($row);
        self::assertSame('Christmas', $row['name']);

        // Clean up
        $conn->delete('holidays', ['day' => $day]);
    }

    public function testUpdate(): void
    {
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        assert($conn instanceof \Doctrine\DBAL\Connection);
        $day = '2023-01-01';
        $conn->insert('holidays', ['day' => $day, 'name' => 'New Year']);
        $conn->update('holidays', ['name' => 'Updated Holiday'], ['day' => $day]);

        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        assert(is_array($row));
        self::assertSame('Updated Holiday', $row['name']);
        $conn->delete('holidays', ['day' => $day]);
    }

    public function testDelete(): void
    {
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        assert($conn instanceof \Doctrine\DBAL\Connection);
        $day = '2023-05-01';
        $conn->insert('holidays', ['day' => $day, 'name' => 'Labor Day']);
        $conn->delete('holidays', ['day' => $day]);

        $row = $conn->fetchAssociative('SELECT * FROM holidays WHERE day = ?', [$day]);
        self::assertFalse((bool) $row, 'Holiday should be deleted from database');
    }

    public function testFindByYear(): void
    {
        if (null === $this->serviceContainer) {
            throw new RuntimeException('Service container not initialized');
        }
        $conn = $this->serviceContainer->get('doctrine.dbal.default_connection');
        assert($conn instanceof \Doctrine\DBAL\Connection);
        $rows = [
            ['day' => '2022-12-25', 'name' => 'Christmas 2022'],
            ['day' => '2023-01-01', 'name' => 'New Year 2023'],
            ['day' => '2023-12-25', 'name' => 'Christmas 2023'],
        ];
        foreach ($rows as $r) {
            $conn->insert('holidays', $r);
        }

        $result = $conn->fetchAllAssociative(
            'SELECT * FROM holidays WHERE day BETWEEN ? AND ? ORDER BY day ASC',
            ['2023-01-01', '2023-12-31'],
        );
        self::assertCount(2, $result);

        foreach ($rows as $row) {
            $conn->delete('holidays', ['day' => $row['day']]);
        }
    }

    public function testToArray(): void
    {
        // Create a new Holiday
        $day = new DateTime('2023-12-25');
        $holiday = new Holiday($day, 'Christmas');

        // Test toArray() method
        $array = $holiday->toArray();
        self::assertSame('25/12/2023', $array['day']);
        self::assertSame('Christmas', $array['description']);

        // No need to persist for this test
    }
}
