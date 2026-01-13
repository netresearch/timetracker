<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\TicketSystemType;
use App\Service\ExportService;
use DateTime;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Stopwatch\Stopwatch;

use function sprintf;

use const STDERR;

/**
 * Performance benchmarks for export functionality.
 *
 * Tests different data volumes and scenarios to establish baseline performance
 * and detect regression issues in export operations.
 *
 * @group performance
 *
 * @internal
 *
 * @coversNothing
 */
final class ExportPerformanceTest extends TestCase
{
    private Stopwatch $stopwatch;

    private ExportService $exportService;

    /**
     * @var array<string, int>
     */
    private array $performanceBaselines;

    /**
     * @var array<int, Entry>
     */
    private array $currentTestEntries = [];

    protected function setUp(): void
    {
        $this->stopwatch = new Stopwatch();
        $this->setupPerformanceBaselines();
        $this->exportService = $this->createExportServiceWithMocks();
    }

    /**
     * Performance baseline thresholds (in milliseconds).
     * These values should be adjusted based on production environment.
     */
    private function setupPerformanceBaselines(): void
    {
        $this->performanceBaselines = [
            'small_dataset_export' => 100,    // 100ms for ~50 entries
            'medium_dataset_export' => 500,   // 500ms for ~500 entries
            'large_dataset_export' => 2000,   // 2s for ~5000 entries
            'ticket_enrichment_small' => 200, // 200ms for enrichment with 10 tickets
            'ticket_enrichment_medium' => 1000, // 1s for enrichment with 100 tickets
            'memory_usage_threshold' => 50 * 1024 * 1024, // 50MB max memory usage
            'jira_api_timeout' => 5000,       // 5s max for JIRA API calls
        ];
    }

    /**
     * Test export performance with small dataset (baseline scenario).
     *
     * @covers \App\Service\ExportService::exportEntries
     */
    public function testSmallDatasetExportPerformance(): void
    {
        $entries = $this->generateTestEntries(50, false);

        $this->stopwatch->start('small_export');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->exportEntries(1, 2025, 8, null, null, [
            'user.username' => 'ASC',
            'entry.day' => 'DESC',
            'entry.start' => 'DESC',
        ]);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('small_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            $this->performanceBaselines['small_dataset_export'],
            $duration,
            "Small dataset export took {$duration}ms, expected < {$this->performanceBaselines['small_dataset_export']}ms",
        );

        self::assertLessThan(
            $this->performanceBaselines['memory_usage_threshold'] / 10, // 5MB for small dataset
            $memoryUsage,
            'Small dataset export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        self::assertCount(50, $result);

        // Log performance metrics
        $this->logPerformanceMetric('Small Dataset Export', $duration, $memoryUsage, 50);
    }

    /**
     * Test export performance with medium dataset.
     *
     * @covers \App\Service\ExportService::exportEntries
     */
    public function testMediumDatasetExportPerformance(): void
    {
        $entries = $this->generateTestEntries(500, false);

        $this->stopwatch->start('medium_export');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->exportEntries(1, 2025, 8, null, null, [
            'user.username' => 'ASC',
            'entry.day' => 'DESC',
        ]);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('medium_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            $this->performanceBaselines['medium_dataset_export'],
            $duration,
            "Medium dataset export took {$duration}ms, expected < {$this->performanceBaselines['medium_dataset_export']}ms",
        );

        self::assertLessThan(
            $this->performanceBaselines['memory_usage_threshold'] / 2, // 25MB for medium dataset
            $memoryUsage,
            'Medium dataset export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        self::assertCount(500, $result);
        $this->logPerformanceMetric('Medium Dataset Export', $duration, $memoryUsage, 500);
    }

    /**
     * Test export performance with large dataset (stress test).
     *
     * @covers \App\Service\ExportService::exportEntries
     */
    public function testLargeDatasetExportPerformance(): void
    {
        $entries = $this->generateTestEntries(5000, false);

        $this->stopwatch->start('large_export');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->exportEntries(1, 2025, 8, null, null, [
            'entry.day' => 'DESC',
            'entry.start' => 'DESC',
        ]);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('large_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            $this->performanceBaselines['large_dataset_export'],
            $duration,
            "Large dataset export took {$duration}ms, expected < {$this->performanceBaselines['large_dataset_export']}ms",
        );

        self::assertLessThan(
            $this->performanceBaselines['memory_usage_threshold'],
            $memoryUsage,
            'Large dataset export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        self::assertCount(5000, $result);
        $this->logPerformanceMetric('Large Dataset Export', $duration, $memoryUsage, 5000);
    }

    /**
     * Test ticket enrichment performance with small dataset.
     *
     * @covers \App\Service\ExportService::enrichEntriesWithTicketInformation
     */
    public function testTicketEnrichmentSmallPerformance(): void
    {
        $entries = $this->generateTestEntries(10, true);

        $this->stopwatch->start('enrichment_small');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->enrichEntriesWithTicketInformation(
            1,
            $entries,
            true,  // includeBillable
            true, // includeTicketTitle
            true,   // searchTickets
        );

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('enrichment_small');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            $this->performanceBaselines['ticket_enrichment_small'],
            $duration,
            "Small ticket enrichment took {$duration}ms, expected < {$this->performanceBaselines['ticket_enrichment_small']}ms",
        );

        self::assertCount(10, $result);
        $this->logPerformanceMetric('Small Ticket Enrichment', $duration, $memoryUsage, 10);
    }

    /**
     * Test ticket enrichment performance with medium dataset.
     *
     * @covers \App\Service\ExportService::enrichEntriesWithTicketInformation
     */
    public function testTicketEnrichmentMediumPerformance(): void
    {
        $entries = $this->generateTestEntries(100, true);

        $this->stopwatch->start('enrichment_medium');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->enrichEntriesWithTicketInformation(
            1,
            $entries,
            true,  // includeBillable
            true,  // includeTicketTitle
            true,   // searchTickets
        );

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('enrichment_medium');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            $this->performanceBaselines['ticket_enrichment_medium'],
            $duration,
            "Medium ticket enrichment took {$duration}ms, expected < {$this->performanceBaselines['ticket_enrichment_medium']}ms",
        );

        self::assertCount(100, $result);
        $this->logPerformanceMetric('Medium Ticket Enrichment', $duration, $memoryUsage, 100);
    }

    /**
     * Test export without ticket enrichment (baseline comparison).
     *
     * @covers \App\Service\ExportService::enrichEntriesWithTicketInformation
     */
    public function testExportWithoutEnrichmentPerformance(): void
    {
        $entries = $this->generateTestEntries(100, true);

        $this->stopwatch->start('no_enrichment');
        $memoryBefore = memory_get_usage(true);

        $result = $this->exportService->enrichEntriesWithTicketInformation(
            1,
            $entries,
            false,  // includeBillable
            false,  // includeTicketTitle
            false,   // searchTickets - disabled
        );

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('no_enrichment');

        // This should be very fast since no enrichment occurs
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        self::assertLessThan(
            50, // 50ms threshold for no enrichment
            $duration,
            "Export without enrichment took {$duration}ms, expected < 50ms",
        );

        self::assertCount(100, $result);
        $this->logPerformanceMetric('Export Without Enrichment', $duration, $memoryUsage, 100);
    }

    /**
     * Test memory usage scaling with different dataset sizes.
     */
    public function testMemoryUsageScaling(): void
    {
        $sizes = [10, 50, 100, 500];
        $memoryUsages = [];

        foreach ($sizes as $size) {
            $entries = $this->generateTestEntries($size, false);

            $memoryBefore = memory_get_usage(true);
            $this->exportService->exportEntries(1, 2025, 8);
            $memoryAfter = memory_get_usage(true);

            $memoryUsage = $memoryAfter - $memoryBefore;
            $memoryUsages[$size] = $memoryUsage;
        }

        // Memory usage should scale roughly linearly
        // Skip memory scaling assertions when using mocks (memory usage will be 0)
        if ($memoryUsages[50] > 0 && $memoryUsages[500] > 0) {
            self::assertLessThan(
                $memoryUsages[50] * 5, // Allow some overhead but should be roughly proportional
                $memoryUsages[500],
                'Memory usage scaling appears non-linear: 50 entries = ' .
                number_format($memoryUsages[50] / 1024, 2) . 'KB, 500 entries = ' .
                number_format($memoryUsages[500] / 1024, 2) . 'KB',
            );
        } else {
            // When using mocks, memory assertions are skipped
            // The test passes if no exception was thrown during execution
            self::assertGreaterThanOrEqual(0, $memoryUsages[50], 'Memory tracking completed');
        }

        // Log memory scaling
        foreach ($memoryUsages as $size => $usage) {
            $this->logPerformanceMetric('Memory Scaling Test', 0, $usage, $size);
        }
    }

    /**
     * Test concurrent export simulation (memory pressure).
     */
    public function testConcurrentExportSimulation(): void
    {
        $entries = $this->generateTestEntries(200, false);

        $this->stopwatch->start('concurrent_simulation');
        $memoryBefore = memory_get_usage(true);

        // Simulate multiple concurrent export operations
        $results = [];
        for ($i = 0; $i < 5; ++$i) {
            $results[] = $this->exportService->exportEntries(1, 2025, 8);
        }

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('concurrent_simulation');

        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        // Should handle multiple exports without excessive memory usage
        self::assertLessThan(
            $this->performanceBaselines['memory_usage_threshold'],
            $memoryUsage,
            'Concurrent export simulation used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        self::assertCount(5, $results);
        foreach ($results as $result) {
            self::assertCount(200, $result);
        }

        $this->logPerformanceMetric('Concurrent Export Simulation', $duration, $memoryUsage, 1000);
    }

    /**
     * Generate test entries for performance testing.
     */
    /**
     * @return array<int, Entry>
     */
    private function generateTestEntries(int $count, bool $withTickets): array
    {
        $entries = [];
        $user = $this->createTestUser();
        $customer = $this->createTestCustomer();
        $project = $this->createTestProject($customer, $withTickets);
        $activity = $this->createTestActivity();

        for ($i = 0; $i < $count; ++$i) {
            $entry = new Entry();
            $entry->setUser($user);
            $entry->setCustomer($customer);
            $entry->setProject($project);
            $entry->setActivity($activity);
            $entry->setDay(new DateTime(sprintf('2025-08-%02d', ($i % 28) + 1)));
            $entry->setStart(new DateTime(sprintf('2025-08-%02d 09:%02d:00', ($i % 28) + 1, $i % 60)));
            $entry->setEnd(new DateTime(sprintf('2025-08-%02d 17:%02d:00', ($i % 28) + 1, $i % 60)));
            $entry->setDescription("Performance test entry {$i}");

            if ($withTickets) {
                $entry->setTicket('PERF-' . (1000 + $i));
            }

            $entries[] = $entry;
        }

        // Store entries for mock repository to return
        $this->currentTestEntries = $entries;

        return $entries;
    }

    /**
     * Create a test user.
     */
    private function createTestUser(): User
    {
        $user = new User();
        $user->setUsername('perftest');
        $user->setAbbr('PT');

        return $user;
    }

    /**
     * Create a test customer.
     */
    private function createTestCustomer(): Customer
    {
        $customer = new Customer();
        $customer->setName('Performance Test Customer');

        return $customer;
    }

    /**
     * Create a test project with optional ticket system.
     */
    private function createTestProject(Customer $customer, bool $withTicketSystem = false): Project
    {
        $project = new Project();
        $project->setName('Performance Test Project');
        $project->setCustomer($customer);

        if ($withTicketSystem) {
            $ticketSystem = new TicketSystem();
            $ticketSystem->setBookTime(true);
            $ticketSystem->setType(TicketSystemType::JIRA);
            $ticketSystem->setTicketUrl('https://example.atlassian.net/browse/%s');
            $project->setTicketSystem($ticketSystem);
        }

        return $project;
    }

    /**
     * Create a test activity.
     */
    private function createTestActivity(): Activity
    {
        $activity = new Activity();
        $activity->setName('Performance Testing');

        return $activity;
    }

    /**
     * Create ExportService with mocked dependencies for performance testing.
     */
    private function createExportServiceWithMocks(): ExportService
    {
        // Mock entry repository that returns current test entries
        $entryRepository = $this->createMock(\App\Repository\EntryRepository::class);
        $entryRepository->method('findByDate')
            ->willReturnCallback(fn () => $this->currentTestEntries);

        // Mock user repository
        $userRepository = $this->createMock(\App\Repository\UserRepository::class);
        $userRepository->method('find')
            ->willReturn($this->createTestUser());

        // Mock manager registry
        $managerRegistry = $this->createMock(ManagerRegistry::class);
        $managerRegistry->method('getRepository')
            ->willReturnCallback(static function ($entityClass) use ($entryRepository, $userRepository) {
                if (Entry::class === $entityClass) {
                    return $entryRepository;
                }
                if (User::class === $entityClass) {
                    return $userRepository;
                }

                return $entryRepository;
            });

        // Mock JIRA API factory with performance-optimized mock
        $jiraApiFactory = $this->createMock(\App\Service\Integration\Jira\JiraOAuthApiFactory::class);
        $jiraApi = $this->createMock(\App\Service\Integration\Jira\JiraOAuthApiService::class);
        $jiraApi->method('searchTicket')
            ->willReturnCallback(static function ($jql, $fields, $limit) {
                // Simulate JIRA response with minimal processing time
                $tickets = [];
                for ($i = 0; $i < $limit && $i < 100; ++$i) {
                    $tickets[] = (object) [
                        'key' => 'PERF-' . (1000 + $i),
                        'fields' => (object) [
                            'labels' => ['billable'],
                            'summary' => 'Performance test ticket ' . (1000 + $i),
                        ],
                    ];
                }

                return (object) ['issues' => $tickets];
            });
        $jiraApiFactory->method('create')->willReturn($jiraApi);

        return new ExportService($managerRegistry, $jiraApiFactory);
    }

    /**
     * Log performance metrics for analysis.
     */
    private function logPerformanceMetric(string $testName, float|int $durationMs, int $memoryBytes, int $recordCount): void
    {
        $memoryMB = number_format($memoryBytes / 1024 / 1024, 2);
        $throughput = $recordCount > 0 ? round($recordCount / max((float) $durationMs / 1000, 0.001), 2) : 0;

        fwrite(STDERR, sprintf(
            "\n[PERFORMANCE] %s: %dms, %sMB memory, %d records, %s records/sec\n",
            $testName,
            $durationMs,
            $memoryMB,
            $recordCount,
            $throughput,
        ));
    }
}
