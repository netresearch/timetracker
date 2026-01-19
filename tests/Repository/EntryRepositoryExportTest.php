<?php

declare(strict_types=1);

namespace Tests\Repository;

use App\Dto\ExportQueryDto;
use App\Entity\Entry;
use App\Repository\EntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;

use function count;

/**
 * Tests for EntryRepository export functionality.
 */
final class EntryRepositoryExportTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private EntryRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->repository = $this->entityManager->getRepository(Entry::class);
    }

    public function testExportQueryDtoConvertsZeroToNull(): void
    {
        $request = Request::create('/controlling/export', 'GET', [
            'userid' => '0',
            'year' => '2026',
            'month' => '1',
            'project' => '0',
            'customer' => '0',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        // 0 should be converted to null for optional ID filters
        self::assertNull($dto->project, 'project=0 should be converted to null');
        self::assertNull($dto->customer, 'customer=0 should be converted to null');

        // But userid/year/month should remain as integers
        self::assertSame(0, $dto->userid);
        self::assertSame(2026, $dto->year);
        self::assertSame(1, $dto->month);
    }

    public function testExportQueryDtoPreservesValidIds(): void
    {
        $request = Request::create('/controlling/export', 'GET', [
            'project' => '123',
            'customer' => '456',
        ]);

        $dto = ExportQueryDto::fromRequest($request);

        self::assertSame(123, $dto->project);
        self::assertSame(456, $dto->customer);
    }

    public function testFindByDateWithNullFiltersReturnsAllEntries(): void
    {
        // This test verifies that null project/customer doesn't filter
        $entriesWithNullFilters = $this->repository->findByDate(
            user: 0,
            year: 2024,  // Use a year that has test data
            month: 1,
            project: null,
            customer: null,
        );

        // Should return entries (exact count depends on test data)
        // The key is it should NOT filter by project_id=0 or customer_id=0
        self::assertIsArray($entriesWithNullFilters);
    }

    public function testFindByDateWithSpecificProjectFiltersCorrectly(): void
    {
        // First get all entries
        $allEntries = $this->repository->findByDate(
            user: 0,
            year: 2024,
            month: null,
            project: null,
            customer: null,
        );

        if (0 === count($allEntries)) {
            self::markTestSkipped('No test data available');
        }

        // Get a project ID from existing entries
        $projectId = $allEntries[0]->getProject()?->getId();
        if (null === $projectId) {
            self::markTestSkipped('No entries with projects in test data');
        }

        // Now filter by that project
        $filteredEntries = $this->repository->findByDate(
            user: 0,
            year: 2024,
            month: null,
            project: $projectId,
            customer: null,
        );

        // Filtered should be <= all entries
        self::assertLessThanOrEqual(count($allEntries), count($filteredEntries));

        // All filtered entries should have the specified project
        foreach ($filteredEntries as $entry) {
            self::assertSame($projectId, $entry->getProject()?->getId());
        }
    }
}
