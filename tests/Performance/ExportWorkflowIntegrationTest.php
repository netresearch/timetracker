<?php

declare(strict_types=1);

namespace Tests\Performance;

use Tests\AbstractWebTestCase;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Integration performance tests for the complete export workflow.
 * 
 * Tests the full end-to-end export process including:
 * - Database queries
 * - Data processing  
 * - Excel generation
 * - HTTP response handling
 * 
 * @group performance
 * @group integration
 * @internal
 * @coversNothing
 */
final class ExportWorkflowIntegrationTest extends AbstractWebTestCase
{
    private Stopwatch $stopwatch;
    private array $performanceBaselines;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stopwatch = new Stopwatch();
        $this->setupPerformanceBaselines();
    }

    /**
     * Performance baseline thresholds for integration tests.
     */
    private function setupPerformanceBaselines(): void
    {
        $this->performanceBaselines = [
            'end_to_end_small' => 1000,    // 1s for small dataset export via HTTP
            'end_to_end_medium' => 3000,   // 3s for medium dataset export via HTTP
            'end_to_end_large' => 15000,   // 15s for large dataset export via HTTP  
            'database_query' => 500,       // 500ms for database queries
            'http_response_size' => 10 * 1024 * 1024, // 10MB max response size
        ];
    }

    /**
     * Test complete export workflow with small dataset.
     * 
     * @covers ExportAction::__invoke
     * @covers ExportService::exportEntries
     * @covers ExportService::enrichEntriesWithTicketInformation
     */
    public function testSmallDatasetEndToEndPerformance(): void
    {
        // Create test data
        $this->createTestDataForExport(50);
        
        $this->stopwatch->start('end_to_end_small');
        $memoryBefore = memory_get_usage(true);
        
        // Perform HTTP request to export endpoint
        $this->client->request('GET', '/controlling/export', [
            'userid' => 1,
            'year' => 2025,
            'month' => 8,
            'project' => 0,
            'customer' => 0,
            'billable' => false,
            'tickettitles' => false,
        ]);
        
        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('end_to_end_small');
        
        // Performance assertions
        $response = $this->client->getResponse();
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(
            $this->performanceBaselines['end_to_end_small'],
            $duration,
            "Small dataset end-to-end export took {$duration}ms"
        );
        
        $this->assertResponseHeaderContains('Content-Type', 'spreadsheetml');
        $this->assertResponseHeaderContains('Content-disposition', 'attachment');
        
        $this->logPerformanceMetric('Small Dataset End-to-End', $duration, $memoryUsage, 50);
    }

    /**
     * Test complete export workflow with medium dataset.
     * 
     * @covers ExportAction::__invoke
     */
    public function testMediumDatasetEndToEndPerformance(): void
    {
        // Create test data
        $this->createTestDataForExport(500);
        
        $this->stopwatch->start('end_to_end_medium');
        $memoryBefore = memory_get_usage(true);
        
        $this->client->request('GET', '/controlling/export', [
            'userid' => 1,
            'year' => 2025,
            'month' => 8,
        ]);
        
        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('end_to_end_medium');
        
        // Performance assertions
        $response = $this->client->getResponse();
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;
        
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertLessThan(
            $this->performanceBaselines['end_to_end_medium'],
            $duration,
            "Medium dataset end-to-end export took {$duration}ms"
        );
        
        // Check response size is reasonable
        $contentLength = strlen($response->getContent() ?? '');
        $this->assertLessThan(
            $this->performanceBaselines['http_response_size'],
            $contentLength,
            "Response size too large: " . number_format($contentLength / 1024 / 1024, 2) . "MB"
        );
        
        $this->logPerformanceMetric('Medium Dataset End-to-End', $duration, $memoryUsage, 500);
    }

    /**
     * Test export with ticket enrichment (external API calls).
     * 
     * @covers ExportService::enrichEntriesWithTicketInformation
     */
    public function testExportWithTicketEnrichmentIntegration(): void
    {
        // Create test data with tickets
        $this->createTestDataForExport(100, true);
        
        $this->stopwatch->start('enrichment_integration');
        $memoryBefore = memory_get_usage(true);
        
        $this->client->request('GET', '/controlling/export', [
            'userid' => 1,
            'year' => 2025,
            'month' => 8,
            'billable' => true,
            'tickettitles' => true,
        ]);
        
        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('enrichment_integration');
        
        // Performance assertions
        $response = $this->client->getResponse();
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;
        
        $this->assertEquals(200, $response->getStatusCode());
        
        // Enrichment should add some overhead but still be reasonable
        $this->assertLessThan(
            $this->performanceBaselines['end_to_end_medium'], 
            $duration,
            "Export with ticket enrichment took {$duration}ms"
        );
        
        $this->logPerformanceMetric('Export with Ticket Enrichment Integration', $duration, $memoryUsage, 100);
    }

    /**
     * Test database query performance in isolation.
     * 
     * @covers EntryRepository::findByDate
     */
    public function testDatabaseQueryPerformance(): void
    {
        // Create test data
        $this->createTestDataForExport(1000);
        
        $this->stopwatch->start('database_query');
        
        /** @var \App\Repository\EntryRepository $entryRepository */
        $entryRepository = $this->getContainer()->get('doctrine')->getRepository(\App\Entity\Entry::class);
        
        // Query entries directly from repository
        $entries = $entryRepository->findByDate(1, 2025, 8, null, null, [
            'user.username' => 'ASC',
            'entry.day' => 'DESC',
            'entry.start' => 'DESC',
        ]);
        
        $event = $this->stopwatch->stop('database_query');
        
        // Performance assertions
        $duration = $event->getDuration();
        
        $this->assertLessThan(
            $this->performanceBaselines['database_query'],
            $duration,
            "Database query took {$duration}ms"
        );
        
        $this->assertGreaterThan(0, count($entries));
        
        $this->logPerformanceMetric('Database Query Performance', $duration, 0, count($entries));
    }

    /**
     * Test concurrent export requests simulation.
     */
    public function testConcurrentExportRequests(): void
    {
        // Create test data
        $this->createTestDataForExport(200);
        
        $this->stopwatch->start('concurrent_requests');
        $memoryBefore = memory_get_usage(true);
        
        // Simulate multiple concurrent requests by making sequential calls
        // In a real scenario, these would be parallel, but we test sequential for memory pressure
        $responses = [];
        for ($i = 0; $i < 3; $i++) {
            $this->client->request('GET', '/controlling/export', [
                'userid' => 1,
                'year' => 2025,
                'month' => 8,
            ]);
            
            $response = $this->client->getResponse();
            $responses[] = $response;
            
            $this->assertEquals(200, $response->getStatusCode());
        }
        
        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('concurrent_requests');
        
        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;
        
        // Should handle multiple requests efficiently
        $this->assertLessThan(
            $this->performanceBaselines['end_to_end_medium'] * 3 * 1.5, // Allow 50% overhead
            $duration,
            "Concurrent requests took {$duration}ms"
        );
        
        $this->assertCount(3, $responses);
        
        $this->logPerformanceMetric('Concurrent Export Requests', $duration, $memoryUsage, 600);
    }

    /**
     * Test export with various filter combinations.
     */
    public function testExportWithFiltersPerformance(): void
    {
        // Create test data with customers and projects
        $this->createTestDataForExport(300);
        
        $filterCombinations = [
            ['project' => 1],
            ['customer' => 1],  
            ['project' => 1, 'customer' => 1],
            ['month' => 0], // All months
        ];
        
        foreach ($filterCombinations as $index => $filters) {
            $this->stopwatch->start("filter_test_{$index}");
            
            $params = array_merge([
                'userid' => 1,
                'year' => 2025,
                'month' => 8,
            ], $filters);
            
            $this->client->request('GET', '/controlling/export', $params);
            
            $event = $this->stopwatch->stop("filter_test_{$index}");
            $response = $this->client->getResponse();
            
            $this->assertEquals(200, $response->getStatusCode());
            
            $duration = $event->getDuration();
            $this->assertLessThan(
                $this->performanceBaselines['end_to_end_medium'],
                $duration,
                "Export with filters " . json_encode($filters) . " took {$duration}ms"
            );
            
            $this->logPerformanceMetric(
                'Export with Filters: ' . json_encode($filters),
                $duration,
                0,
                300
            );
        }
    }

    /**
     * Test memory usage during large export processing.
     */
    public function testLargeExportMemoryUsage(): void
    {
        // Create large dataset
        $this->createTestDataForExport(2000);
        
        $memoryBefore = memory_get_usage(true);
        $peakBefore = memory_get_peak_usage(true);
        
        $this->client->request('GET', '/controlling/export', [
            'userid' => 1,
            'year' => 2025,
            'month' => 8,
        ]);
        
        $memoryAfter = memory_get_usage(true);
        $peakAfter = memory_get_peak_usage(true);
        
        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $memoryUsage = $memoryAfter - $memoryBefore;
        $peakMemoryIncrease = $peakAfter - $peakBefore;
        
        // Memory usage should be reasonable for large datasets
        $this->assertLessThan(
            100 * 1024 * 1024, // 100MB
            $peakMemoryIncrease,
            "Peak memory increase too high: " . number_format($peakMemoryIncrease / 1024 / 1024, 2) . "MB"
        );
        
        $this->logPerformanceMetric(
            'Large Export Memory Usage',
            0,
            $peakMemoryIncrease,
            2000
        );
    }

    /**
     * Create test data for export performance testing.
     */
    private function createTestDataForExport(int $entryCount, bool $withTickets = false): void
    {
        $entityManager = $this->getContainer()->get('doctrine')->getManager();
        
        // Create test user
        $user = new \App\Entity\User();
        $user->setUsername('exporttest')
             ->setAbbr('ET')
             ->setPassword('test');
        $entityManager->persist($user);
        
        // Create test customer
        $customer = new \App\Entity\Customer();
        $customer->setName('Export Performance Customer');
        $entityManager->persist($customer);
        
        // Create test project
        $project = new \App\Entity\Project();
        $project->setName('Export Performance Project')
               ->setCustomer($customer);
        
        if ($withTickets) {
            $ticketSystem = new \App\Entity\TicketSystem();
            $ticketSystem->setBookTime(true)
                        ->setType(\App\Enum\TicketSystemType::JIRA)
                        ->setTicketUrl('https://example.atlassian.net/browse/%s');
            $entityManager->persist($ticketSystem);
            $project->setTicketSystem($ticketSystem);
        }
        
        $entityManager->persist($project);
        
        // Create test activity
        $activity = new \App\Entity\Activity();
        $activity->setName('Export Performance Activity');
        $entityManager->persist($activity);
        
        // Create test entries
        for ($i = 0; $i < $entryCount; $i++) {
            $entry = new \App\Entity\Entry();
            $day = new \DateTime(sprintf('2025-08-%02d', ($i % 28) + 1));
            $start = clone $day;
            $start->setTime(9, $i % 60);
            $end = clone $start;
            $end->modify('+8 hours');
            
            $entry->setUser($user)
                  ->setCustomer($customer)
                  ->setProject($project)
                  ->setActivity($activity)
                  ->setDay($day)
                  ->setStart($start)
                  ->setEnd($end)
                  ->setDescription("Export performance test entry {$i}")
                  ->setDuration(8.0);
            
            if ($withTickets) {
                $entry->setTicket("EXPORT-" . (1000 + $i));
            }
            
            $entityManager->persist($entry);
            
            // Batch flush to avoid memory issues
            if ($i % 100 === 0) {
                $entityManager->flush();
                $entityManager->clear();
                
                // Re-fetch entities for next batch
                $user = $entityManager->find(\App\Entity\User::class, $user->getId());
                $customer = $entityManager->find(\App\Entity\Customer::class, $customer->getId());
                $project = $entityManager->find(\App\Entity\Project::class, $project->getId());
                $activity = $entityManager->find(\App\Entity\Activity::class, $activity->getId());
            }
        }
        
        $entityManager->flush();
    }

    /**
     * Assert response header contains expected value.
     */
    private function assertResponseHeaderContains(string $headerName, string $expectedValue): void
    {
        $response = $this->client->getResponse();
        $headerValue = $response->headers->get($headerName);
        
        $this->assertNotNull($headerValue, "Header {$headerName} not found");
        $this->assertStringContainsString(
            $expectedValue,
            $headerValue,
            "Header {$headerName} does not contain '{$expectedValue}'. Actual: {$headerValue}"
        );
    }

    /**
     * Log performance metrics for analysis.
     */
    private function logPerformanceMetric(string $testName, int $durationMs, int $memoryBytes, int $recordCount): void
    {
        $memoryMB = number_format($memoryBytes / 1024 / 1024, 2);
        $throughput = $recordCount > 0 && $durationMs > 0 ? round($recordCount / ($durationMs / 1000), 2) : 0;
        
        fwrite(STDERR, sprintf(
            "\n[INTEGRATION PERFORMANCE] %s: %dms, %sMB memory, %d records, %s records/sec\n",
            $testName,
            $durationMs,
            $memoryMB,
            $recordCount,
            $throughput
        ));
    }
}