<?php

declare(strict_types=1);

namespace Tests\Traits;

use Exception;
use Throwable;

use function method_exists;
use function trim;

/**
 * Database test functionality trait.
 *
 * Provides transaction isolation, database reset, and query builder setup
 * for test cases requiring database operations.
 */
trait DatabaseTestTrait
{
    protected \Doctrine\DBAL\Connection|null $connection = null;

    protected \Doctrine\DBAL\Query\QueryBuilder|null $queryBuilder = null;

    protected string $filepath = '/../sql/unittest/002_testdata.sql';

    /**
     * The initial state of a table used to assert integrity after a DEV test.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected array|null $tableInitialState = null;

    /**
     * Flag to track if database has been initialized.
     */
    private static bool $databaseInitialized = false;

    protected bool $useTransactions = true;

    /**
     * Initialize database connection and transaction for test isolation.
     */
    protected function initializeDatabase(): void
    {
        // Reset database state if needed (only once per process)
        $this->resetDatabase();

        // Ensure we have a Doctrine DBAL connection reference
        if ($this->serviceContainer === null) {
            throw new \RuntimeException('Service container not initialized');
        }
        $dbal = $this->serviceContainer->get('doctrine.dbal.default_connection');

        // Enable savepoints to speed nested transactions
        $dbal->setNestTransactionsWithSavepoints(true);

        $this->connection = $dbal;
        $this->queryBuilder = $dbal->createQueryBuilder();

        // Begin a transaction to isolate test database changes (reliable via DBAL)
        if ($this->useTransactions) {
            try {
                $dbal->beginTransaction();
            } catch (Exception $e) {
                error_log('Transaction begin failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Rollback database transaction to restore original state.
     */
    protected function cleanupDatabase(): void
    {
        // Roll back the transaction to restore the database to its original state (via DBAL)
        if ($this->useTransactions && $this->serviceContainer) {
            try {
                if ($this->serviceContainer->has('doctrine.dbal.default_connection')) {
                    $dbal = $this->serviceContainer->get('doctrine.dbal.default_connection');
                                // Be tolerant: attempt rollback but ignore if not active
                    try {
                        $dbal->rollBack();
                    } catch (Throwable) {
                    }
                }
            } catch (Exception) {
                // ignore
            }
        }

        // Clear entity manager to prevent stale data between tests, tolerate shut down kernel
        try {
            if ($this->serviceContainer && $this->serviceContainer->has('doctrine')) {
                $doctrine = $this->serviceContainer->get('doctrine');
                        $entityManager = $doctrine->getManager();
                        $entityManager->clear();
            }
        } catch (Throwable) {
            // Ignore if kernel has been shut down during the test
        }
    }

    /**
     * Set initial database state for DEV user tests.
     */
    protected function setInitialDbState(string $tableName): void
    {
        if ($this->queryBuilder !== null) {
            $qb = $this->queryBuilder
                ->select('*')
                ->from($tableName)
            ;
            $result = $qb->executeQuery();
            $this->tableInitialState = $result->fetchAllAssociative();
        }
    }

    /**
     * Assert database state matches initial state.
     * Only call in test where setInitialDbState was called before.
     */
    protected function assertDbState(string $tableName): void
    {
        if ($this->queryBuilder !== null) {
            $qb = $this->queryBuilder
                ->select('*')
                ->from($tableName)
            ;
            $result = $qb->executeQuery();
            $newTableState = $result->fetchAllAssociative();
        } else {
            $newTableState = [];
        }
        self::assertSame($this->tableInitialState, $newTableState);
        $this->tableInitialState = null;
    }

    /**
     * Reset database to initial state only when needed.
     */
    protected function resetDatabase(?string $filepath = null): void
    {
        if (!self::$databaseInitialized || null !== $filepath) {
            $this->loadTestData($filepath);
            self::$databaseInitialized = true;
        } elseif (null === $this->queryBuilder || null === $this->connection) {
            // Ensure queryBuilder and connection are available even if loadTestData wasn't called
            if ($this->serviceContainer === null) {
                throw new \RuntimeException('Service container not initialized');
            }
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');
            $this->queryBuilder = $connection->createQueryBuilder();

            // Also make sure $this->connection is properly initialized
            $this->connection = $connection;
        }
    }

    /**
     * For tests that need a completely fresh database state.
     * Call this method at the beginning of the test to force a complete database reset.
     */
    protected function forceReset(?string $filepath = null): void
    {
        // Temporarily disable transactions
        $this->useTransactions = false;

        // Force a complete database reset only when explicitly requested by tests that need it
        self::$databaseInitialized = false;
        $this->resetDatabase($filepath);

        // Ensure kernel is shut down before creating a fresh client
        static::ensureKernelShutdown();

        // Create a fresh client
        $this->client = static::createClient();
        $this->serviceContainer = $this->client->getContainer();

        // Re-enable transactions for the next test
        $this->useTransactions = true;
    }

    /**
     * Clear static database state between test runs (important for parallel testing).
     */
    protected static function clearDatabaseState(): void
    {
        self::$databaseInitialized = false;
    }
}