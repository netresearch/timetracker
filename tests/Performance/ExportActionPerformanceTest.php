<?php

declare(strict_types=1);

namespace Tests\Performance;

use App\Controller\Controlling\ExportAction;
use App\Dto\ExportQueryDto;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Service\ExportService;
use DateTime;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Stopwatch\Stopwatch;

use function sprintf;
use function strlen;

use const STDERR;

/**
 * Performance benchmarks for ExportAction (PhpSpreadsheet processing).
 *
 * Tests the complete export pipeline including Excel generation,
 * focusing on memory usage and processing time for different data volumes.
 *
 * @group performance
 *
 * @internal
 *
 * @coversNothing
 */
final class ExportActionPerformanceTest extends TestCase
{
    private Stopwatch $stopwatch;
    /**
     * @var array<string, int>
     */
    private array $performanceBaselines;
    private string $tempDir;

    protected function setUp(): void
    {
        $this->stopwatch = new Stopwatch();
        $this->setupPerformanceBaselines();
        $this->tempDir = sys_get_temp_dir() . '/export_performance_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0o777, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up temporary files
        if (is_dir($this->tempDir)) {
            $globResult = glob($this->tempDir . '/*');
            array_map('unlink', false !== $globResult ? $globResult : []);
            rmdir($this->tempDir);
        }
    }

    /**
     * Performance baseline thresholds for ExportAction.
     */
    private function setupPerformanceBaselines(): void
    {
        $this->performanceBaselines = [
            'small_excel_export' => 750,     // 750ms for 50 entries with Excel generation (Docker environment)
            'medium_excel_export' => 4500,   // 4.5s for 500 entries with Excel generation (Docker environment)
            'large_excel_export' => 11000,   // 11s for 5000 entries with Excel generation (Docker environment)
            'excel_memory_threshold' => 100 * 1024 * 1024, // 100MB for Excel processing
            'template_loading' => 100,       // 100ms for template loading
            'statistics_calculation' => 200, // 200ms for statistics calculation
        ];
    }

    /**
     * Test small dataset Excel export (baseline scenario).
     *
     * @covers \App\Controller\Controlling\ExportAction::__invoke
     */
    public function testSmallDatasetExcelExportPerformance(): void
    {
        $exportAction = $this->createExportActionWithMocks(50, false);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

        $this->stopwatch->start('small_excel_export');
        $memoryBefore = memory_get_usage(true);

        $response = $exportAction($request, $exportQueryDto);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('small_excel_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            $this->performanceBaselines['small_excel_export'],
            $duration,
            "Small Excel export took {$duration}ms, expected < {$this->performanceBaselines['small_excel_export']}ms",
        );

        $this->assertLessThan(
            $this->performanceBaselines['excel_memory_threshold'] / 4, // 25MB for small dataset
            $memoryUsage,
            'Small Excel export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $contentType = $response->headers->get('Content-Type');
        self::assertNotNull($contentType, 'Content-Type header should not be null');
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $contentType,
        );

        $this->logPerformanceMetric('Small Excel Export', (int) $duration, (int) $memoryUsage, 50);
    }

    /**
     * Test medium dataset Excel export.
     *
     * @covers \App\Controller\Controlling\ExportAction::__invoke
     */
    public function testMediumDatasetExcelExportPerformance(): void
    {
        $exportAction = $this->createExportActionWithMocks(500, false);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

        $this->stopwatch->start('medium_excel_export');
        $memoryBefore = memory_get_usage(true);

        $response = $exportAction($request, $exportQueryDto);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('medium_excel_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            $this->performanceBaselines['medium_excel_export'],
            $duration,
            "Medium Excel export took {$duration}ms, expected < {$this->performanceBaselines['medium_excel_export']}ms",
        );

        $this->assertLessThan(
            $this->performanceBaselines['excel_memory_threshold'] / 2, // 50MB for medium dataset
            $memoryUsage,
            'Medium Excel export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->logPerformanceMetric('Medium Excel Export', (int) $duration, (int) $memoryUsage, 500);
    }

    /**
     * Test large dataset Excel export (stress test).
     *
     * @covers \App\Controller\Controlling\ExportAction::__invoke
     */
    public function testLargeDatasetExcelExportPerformance(): void
    {
        $exportAction = $this->createExportActionWithMocks(5000, false);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

        $this->stopwatch->start('large_excel_export');
        $memoryBefore = memory_get_usage(true);

        $response = $exportAction($request, $exportQueryDto);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('large_excel_export');

        // Performance assertions
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            $this->performanceBaselines['large_excel_export'],
            $duration,
            "Large Excel export took {$duration}ms, expected < {$this->performanceBaselines['large_excel_export']}ms",
        );

        $this->assertLessThan(
            $this->performanceBaselines['excel_memory_threshold'],
            $memoryUsage,
            'Large Excel export used ' . number_format($memoryUsage / 1024 / 1024, 2) . 'MB memory',
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->logPerformanceMetric('Large Excel Export', (int) $duration, (int) $memoryUsage, 5000);
    }

    /**
     * Test Excel export with ticket enrichment performance.
     *
     * @covers \App\Controller\Controlling\ExportAction::__invoke
     */
    public function testExcelExportWithTicketEnrichmentPerformance(): void
    {
        $exportAction = $this->createExportActionWithMocks(200, true);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8, true, true);

        $this->stopwatch->start('excel_export_enriched');
        $memoryBefore = memory_get_usage(true);

        $response = $exportAction($request, $exportQueryDto);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('excel_export_enriched');

        // Performance assertions - enrichment should add some overhead
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        $this->assertLessThan(
            $this->performanceBaselines['medium_excel_export'], // Should still be within medium baseline
            $duration,
            "Excel export with enrichment took {$duration}ms",
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->logPerformanceMetric('Excel Export with Ticket Enrichment', (int) $duration, (int) $memoryUsage, 200);
    }

    /**
     * Test statistics calculation performance (holidays/sick days).
     *
     * @covers \App\Controller\Controlling\ExportAction::__invoke
     */
    public function testStatisticsCalculationPerformance(): void
    {
        $exportAction = $this->createExportActionWithMocks(1000, false, true);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

        $this->stopwatch->start('statistics_calculation');
        $memoryBefore = memory_get_usage(true);

        $response = $exportAction($request, $exportQueryDto);

        $memoryAfter = memory_get_usage(true);
        $event = $this->stopwatch->stop('statistics_calculation');

        // Statistics processing should be efficient
        $duration = $event->getDuration();
        $memoryUsage = $memoryAfter - $memoryBefore;

        // Should handle statistics without significant performance impact
        $this->assertLessThan(
            $this->performanceBaselines['large_excel_export'],
            $duration,
            "Export with statistics took {$duration}ms",
        );

        $this->assertInstanceOf(Response::class, $response);
        $this->logPerformanceMetric('Export with Statistics Calculation', (int) $duration, (int) $memoryUsage, 1000);
    }

    /**
     * Test Excel file size scaling with dataset size.
     */
    public function testExcelFileSizeScaling(): void
    {
        $sizes = [50, 200, 1000];
        $fileSizes = [];

        foreach ($sizes as $size) {
            $exportAction = $this->createExportActionWithMocks($size, false);
            $request = $this->createExportRequest(1, 2025, 8);
            $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

            $response = $exportAction($request, $exportQueryDto);
            $content = $response->getContent();
            $fileSize = strlen((string) ($content ?? ''));
            $fileSizes[$size] = $fileSize;
        }

        // File size should scale reasonably (not exponentially)
        $this->assertLessThan(
            $fileSizes[200] * 10, // Allow reasonable overhead but not exponential growth
            $fileSizes[1000],
            'Excel file size scaling appears excessive: 200 entries = ' .
            number_format($fileSizes[200] / 1024, 2) . 'KB, 1000 entries = ' .
            number_format($fileSizes[1000] / 1024, 2) . 'KB',
        );

        // Log file size scaling
        foreach ($fileSizes as $size => $fileSize) {
            $this->logPerformanceMetric('File Size Scaling', 0, $fileSize, $size);
        }
    }

    /**
     * Test memory cleanup after Excel generation.
     */
    public function testMemoryCleanupAfterExcelGeneration(): void
    {
        $exportAction = $this->createExportActionWithMocks(500, false);
        $request = $this->createExportRequest(1, 2025, 8);
        $exportQueryDto = $this->createExportQueryDto(1, 2025, 8);

        $memoryBefore = memory_get_usage(true);

        // Generate Excel file
        $response = $exportAction($request, $exportQueryDto);
        $memoryPeak = memory_get_peak_usage(true);

        // Force garbage collection
        unset($response);
        gc_collect_cycles();

        $memoryAfter = memory_get_usage(true);
        $memoryDifference = $memoryAfter - $memoryBefore;
        $peakUsage = $memoryPeak - $memoryBefore;

        // Memory should be mostly cleaned up
        $this->assertLessThan(
            $peakUsage / 2, // Should retain less than 50% of peak memory usage
            $memoryDifference,
            'Memory not properly cleaned up. Peak usage: ' .
            number_format($peakUsage / 1024 / 1024, 2) .
            'MB, Remaining: ' .
            number_format($memoryDifference / 1024 / 1024, 2) . 'MB',
        );

        $this->logPerformanceMetric('Memory Cleanup Test', 0, $memoryDifference, 500);
    }

    /**
     * Create ExportAction with mocked dependencies.
     */
    private function createExportActionWithMocks(int $entryCount, bool $withTickets = false, bool $withStats = false): ExportAction
    {
        $exportAction = new ExportAction();

        // Mock export service
        $exportService = $this->createMock(ExportService::class);
        $exportService->method('exportEntries')
            ->willReturn($this->generateTestEntries($entryCount, $withTickets, $withStats));
        $exportService->method('enrichEntriesWithTicketInformation')
            ->willReturnCallback(static function ($userId, $entries) {
                return $entries; // Return entries as-is for performance testing
            });
        $exportService->method('getUsername')
            ->willReturn('perftest');

        $exportAction->setExportService($exportService);

        // Mock kernel for template file path
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')
            ->willReturn($this->createMockTemplateFile());

        // Use reflection to set private properties
        $reflection = new ReflectionClass($exportAction);
        $kernelProperty = $reflection->getProperty('kernel');
        $kernelProperty->setValue($exportAction, $kernel);

        // Mock parameters
        $params = new ParameterBag([
            'app_show_billable_field_in_export' => false,
        ]);
        $paramsProperty = $reflection->getProperty('params');
        $paramsProperty->setValue($exportAction, $params);

        // Mock container to prevent initialization errors
        $container = $this->createMock(\Symfony\Component\DependencyInjection\ContainerInterface::class);

        // Mock session service
        $session = $this->createMock(\Symfony\Component\HttpFoundation\Session\SessionInterface::class);
        $session->method('has')->willReturn(true);

        // Mock security authorization checker
        $authChecker = $this->createMock(\Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        // Mock security token storage with user
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $tokenStorage = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnMap([
            ['session', \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $session],
            ['security.authorization_checker', \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $authChecker],
            ['security.token_storage', \Symfony\Component\DependencyInjection\ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE, $tokenStorage],
        ]);

        $exportAction->setContainer($container);

        return $exportAction;
    }

    /**
     * Create a mock Excel template file for testing.
     */
    private function createMockTemplateFile(): string
    {
        // Create public directory structure to match real application
        $publicDir = $this->tempDir . '/public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0o777, true);
        }

        $templatePath = $publicDir . '/template.xlsx';

        // Create a minimal Excel template
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Set up headers similar to the real template
        $sheet->setCellValue('A2', 'Date');
        $sheet->setCellValue('B2', 'Start');
        $sheet->setCellValue('C2', 'End');
        $sheet->setCellValue('D2', 'Customer');
        $sheet->setCellValue('E2', 'Project');
        $sheet->setCellValue('F2', 'Activity');
        $sheet->setCellValue('G2', 'Description');
        $sheet->setCellValue('H2', 'Ticket');
        $sheet->setCellValue('I2', 'Duration');
        $sheet->setCellValue('J2', 'User');
        $sheet->setCellValue('K2', 'External Reporter');
        $sheet->setCellValue('L2', 'External Summary');
        $sheet->setCellValue('M2', 'External Labels');

        // Create additional sheets for statistics
        $spreadsheet->createSheet();
        $spreadsheet->createSheet();

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($templatePath);

        return $this->tempDir;
    }

    /**
     * Create test request for export.
     */
    private function createExportRequest(int $userId, int $year, int $month): Request
    {
        $request = new Request();
        $request->query->set('userid', (string) $userId);
        $request->query->set('year', (string) $year);
        $request->query->set('month', (string) $month);

        // Mock session for authentication check
        $session = $this->createMock(\Symfony\Component\HttpFoundation\Session\SessionInterface::class);
        $session->method('get')->willReturn($userId);
        $request->setSession($session);

        return $request;
    }

    /**
     * Create ExportQueryDto for testing.
     */
    private function createExportQueryDto(int $userId, int $year, int $month, bool $billable = false, bool $ticketTitles = false): ExportQueryDto
    {
        $dto = new ExportQueryDto(
            userid: $userId,
            year: $year,
            month: $month,
            project: 0,
            customer: 0,
            billable: $billable,
            tickettitles: $ticketTitles,
        );

        return $dto;
    }

    /**
     * Generate test entries for performance testing.
     */
    /**
     * @return array<int, Entry>
     */
    private function generateTestEntries(int $count, bool $withTickets = false, bool $withStats = false): array
    {
        $entries = [];
        $user = $this->createTestUser();
        $customer = $this->createTestCustomer();
        $project = $this->createTestProject($customer);
        $activity = $this->createTestActivity($withStats);

        for ($i = 0; $i < $count; ++$i) {
            $entry = new Entry();
            $entry->setUser($user);
            $entry->setCustomer($customer);
            $entry->setProject($project);
            $entry->setActivity($activity);
            $entry->setDay(new DateTime(sprintf('2025-08-%02d', ($i % 28) + 1)));
            $entry->setStart(new DateTime(sprintf('2025-08-%02d 09:%02d:00', ($i % 28) + 1, $i % 60)));
            $entry->setEnd(new DateTime(sprintf('2025-08-%02d 17:%02d:00', ($i % 28) + 1, $i % 60)));
            $entry->setDescription("Performance test entry {$i} with longer description for realistic Excel cell content");
            $entry->setExternalReporter('external.reporter@example.com');
            $entry->setExternalSummary('External summary for performance testing with longer text content');
            $entry->setExternalLabels(['performance', 'test', 'benchmark']);

            if ($withTickets) {
                $entry->setTicket('PERF-' . (1000 + $i));
            }

            $entries[] = $entry;
        }

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
     * Create a test project.
     */
    private function createTestProject(Customer $customer): Project
    {
        $project = new Project();
        $project->setName('Performance Test Project');
        $project->setCustomer($customer);

        return $project;
    }

    /**
     * Create a test activity with optional statistics flags.
     */
    private function createTestActivity(bool $withStats = false): Activity
    {
        $activity = new Activity();
        $activity->setName('Performance Testing Activity');

        if ($withStats) {
            // Some entries will be holidays/sick days for statistics testing
            if (random_int(1, 10) <= 2) { // 20% chance
                $activity->setName($activity->getName() . ' (Holiday)');
            }
        }

        return $activity;
    }

    /**
     * Log performance metrics for analysis.
     */
    private function logPerformanceMetric(string $testName, int $durationMs, int $memoryBytes, int $recordCount): void
    {
        $memoryMB = number_format($memoryBytes / 1024 / 1024, 2);
        $throughput = $recordCount > 0 ? round($recordCount / max($durationMs / 1000, 0.001), 2) : 0;

        fwrite(STDERR, sprintf(
            "\n[EXCEL PERFORMANCE] %s: %dms, %sMB memory, %d records, %s records/sec\n",
            $testName,
            $durationMs,
            $memoryMB,
            $recordCount,
            $throughput,
        ));
    }
}
