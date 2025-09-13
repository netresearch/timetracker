<?php

declare(strict_types=1);

namespace Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use Tests\Traits\AuthenticationTestTrait;
use Tests\Traits\DatabaseTestTrait;
use Tests\Traits\HttpClientTrait;
use Tests\Traits\JsonAssertionsTrait;
use Tests\Traits\TestDataTrait;

/**
 * Abstract base test case that combines all test functionality.
 *
 * This class serves as a facade that uses focused traits for different responsibilities:
 * - DatabaseTestTrait: Transaction isolation, database reset, query builder setup
 * - AuthenticationTestTrait: User login, session management, authentication helpers
 * - JsonAssertionsTrait: JSON response validation, API testing helpers
 * - TestDataTrait: Fixture loading, test data management
 * - HttpClientTrait: Client setup, HTTP request helpers
 *
 * Maintains backward compatibility while providing better separation of concerns.
 */
abstract class AbstractWebTestCase extends SymfonyWebTestCase
{
    use AuthenticationTestTrait;
    use DatabaseTestTrait;
    use HttpClientTrait;
    use JsonAssertionsTrait;
    use TestDataTrait;

    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;
        parent::ensureKernelShutdown();
        if ($wasBooted) {
            @restore_exception_handler();
        }
    }

    /**
     * Set up before each test.
     * Coordinates initialization across all traits.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Initialize HTTP client (from HttpClientTrait)
        $this->initializeHttpClient();

        // Initialize database and transactions (from DatabaseTestTrait)
        $this->initializeDatabase();

        // Authenticate user in session (from AuthenticationTestTrait)
        $this->logInSession();

        // Avoid repeatedly setting translator/request locale to reduce overhead
        // Keep defaults from framework config; individual tests can override if needed
    }

    /**
     * Tear down after each test.
     * Coordinates cleanup across all traits.
     */
    protected function tearDown(): void
    {
        // Clean up database (from DatabaseTestTrait)
        $this->cleanupDatabase();

        parent::tearDown();
    }

    /**
     * Clear static state between test runs (important for parallel testing).
     * This helps ensure isolated test environments for parallel runs.
     * Coordinates cleanup across all traits.
     */
    public static function tearDownAfterClass(): void
    {
        // Clear database state (from DatabaseTestTrait)
        self::clearDatabaseState();

        // Clear test data state (from TestDataTrait)
        self::clearTestDataState();

        parent::tearDownAfterClass();
    }
}
