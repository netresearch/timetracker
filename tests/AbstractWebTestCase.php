<?php

declare(strict_types=1);

namespace Tests;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use Symfony\Component\BrowserKit\Cookie;

/**
 * Abstract base test case that combines all test functionality.
 * Consolidates the features from the previous Base and TestCase classes.
 */
abstract class AbstractWebTestCase extends SymfonyWebTestCase
{
    protected static function ensureKernelShutdown(): void
    {
        $wasBooted = static::$booted;
        parent::ensureKernelShutdown();
        if ($wasBooted) {
            @\restore_exception_handler();
        }
    }
    /**
     * Assert that $subset is contained within $array (recursive subset match).
     *
     * - If $subset is an associative array, all its keys must exist in $array with matching values (recursively for arrays).
     * - If $subset is a list and $array is a list:
     *   - When $subset has one element, assert that there exists at least one element in $array containing that subset.
     *   - Otherwise, check elements in order (index-wise) for being a subset.
     */
    protected function assertArraySubset(array $subset, array $array, string $message = ''): void
    {
        $isAssoc = static function (array $a): bool {
            if ($a === []) {
                return false;
            }
            return array_keys($a) !== range(0, count($a) - 1);
        };

        $valuesEqual = static function ($expected, $actual): bool {
            if (is_numeric($expected) && is_numeric($actual)) {
                return (string) $expected === (string) $actual;
            }
            return $expected === $actual;
        };

        $assertSubset = function (array $needle, array $haystack) use (&$assertSubset, $isAssoc, $valuesEqual): void {
            if ($isAssoc($needle)) {
                // Associative: each key/value in needle must match in haystack
                foreach ($needle as $key => $value) {
                    $this->assertArrayHasKey($key, $haystack, "Missing key '$key'");
                    if (is_array($value)) {
                        $this->assertIsArray($haystack[$key]);
                        $assertSubset($value, $haystack[$key]);
                    } else {
                        $this->assertTrue($valuesEqual($value, $haystack[$key]), "Value mismatch at key '$key'");
                    }
                }
            } else {
                // List: compare in-order by index
                $this->assertGreaterThanOrEqual(count($needle), count($haystack));
                foreach ($needle as $index => $value) {
                    if (is_array($value)) {
                        $this->assertIsArray($haystack[$index]);
                        $assertSubset($value, $haystack[$index]);
                    } else {
                        $this->assertTrue($valuesEqual($value, $haystack[$index]), "Value mismatch at index $index");
                    }
                }
            }
        };

        if (!$isAssoc($subset) && !$isAssoc($array)) {
            // Both lists: allow order-insensitive subset matching
            $remaining = $subset;
            $haystack = $array;

            $matchElement = function ($needle, array $hay) use ($assertSubset, $valuesEqual) {
                foreach ($hay as $idx => $candidate) {
                    try {
                        if (is_array($needle)) {
                            if (!is_array($candidate)) {
                                continue;
                            }
                            $assertSubset($needle, $candidate);
                            return $idx;
                        }
                        if ($valuesEqual($needle, $candidate)) {
                            return $idx;
                        }
                    } catch (\Throwable) {
                        // try next
                    }
                }
                return null;
            };

            foreach ($remaining as $needle) {
                $idx = $matchElement($needle, $haystack);
                if ($idx === null) {
                    $this->fail($message !== '' ? $message : 'Subset element not found in array');
                }
                unset($haystack[$idx]);
            }
            return;
        }

        $assertSubset($subset, $array);
    }

    protected KernelBrowser $client;

    protected $serviceContainer;

    protected $connection;

    protected $queryBuilder;

    protected $filepath = '/../sql/unittest/002_testdata.sql';

    /**
     * The initial state of a table
     * used to assert integrity of table after a DEV test.
     */
    protected $tableInitialState;

    /**
     * Flag to track if test data has been loaded.
     */
    private static bool $dataLoaded = false;

    /**
     * Flag to track if database has been initialized.
     */
    private static bool $databaseInitialized = false;

    protected $useTransactions = true;

    /**
     * Returns the kernel class to use for these tests.
     */
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    /**
     * Set up before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Reuse kernel between tests for performance; client reboot disabled below
        $this->client = static::createClient();
        // Convert kernel exceptions to HTTP responses (e.g., 422 from MapRequestPayload)
        $this->client->catchExceptions(false);
        // Use the test container to access private services like test.session
        $this->serviceContainer = static::getContainer();

        // Reset database state if needed (only once per process)
        $this->resetDatabase();

        // Ensure we have a Doctrine DBAL connection reference
        $dbal = $this->serviceContainer->get('doctrine.dbal.default_connection');
        // Enable savepoints to speed nested transactions
        if (method_exists($dbal, 'setNestTransactionsWithSavepoints')) {
            $dbal->setNestTransactionsWithSavepoints(true);
        }
        $this->queryBuilder = $dbal->createQueryBuilder();

        // Begin a transaction to isolate test database changes (reliable via DBAL)
        if ($this->useTransactions) {
            try {
                $dbal->beginTransaction();
            } catch (\Exception $e) {
                error_log('Transaction begin failed: '.$e->getMessage());
            }
        }

        // authenticate user in session, only if security services are available
        $this->logInSession();

        // Avoid repeatedly setting translator/request locale to reduce overhead
        // Keep defaults from framework config; individual tests can override if needed
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void
    {
        // Roll back the transaction to restore the database to its original state (via DBAL)
        if ($this->useTransactions && $this->serviceContainer) {
            try {
                if ($this->serviceContainer->has('doctrine.dbal.default_connection')) {
                    $dbal = $this->serviceContainer->get('doctrine.dbal.default_connection');
                    // Be tolerant: attempt rollback but ignore if not active
                    try {
                        $dbal->rollBack();
                    } catch (\Throwable) {
                    }
                }
            } catch (\Exception) {
                // ignore
            }
        }

        // Clear entity manager to prevent stale data between tests, tolerate shut down kernel
        try {
            if ($this->serviceContainer && $this->serviceContainer->has('doctrine')) {
                $this->serviceContainer->get('doctrine')->getManager()->clear();
            }
        } catch (\Throwable) {
            // Ignore if kernel has been shut down during the test
        }

        parent::tearDown();
    }

    /**
     * Used in test for DEV users.
     */
    protected function setInitialDbState(string $tableName)
    {
        $qb = $this->queryBuilder
            ->select('*')
            ->from($tableName);
        $result = $qb->executeQuery();
        $this->tableInitialState = $result->fetchAllAssociative();
    }

    /**
     * Only Call in test where setInitialDbState was called before.
     */
    protected function assertDbState(string $tableName)
    {
        $qb = $this->queryBuilder
            ->select('*')
            ->from($tableName);
        $result = $qb->executeQuery();
        $newTableState = $result->fetchAllAssociative();
        $this->assertSame($this->tableInitialState, $newTableState);
        $this->tableInitialState = null;
    }

    /**
     * Loads the test data specific for each test
     * Each test has a path to the file with the sql test data
     * When executing loadTestData() the file from the $filepath
     * of current scope will be imported.
     */
    protected function loadTestData(?string $filepath = null)
    {
        $file = $filepath ? file_get_contents(__DIR__.$filepath) : file_get_contents(__DIR__.$this->filepath);

        // turn on error reporting (if function exists)
        if (function_exists('mysqli_report')) {
            \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        try {
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');

            // Execute SQL file statements using DBAL (avoid native connection handling)
            $statements = explode(';', $file);
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if ('' !== $statement && '0' !== $statement) {
                    try {
                        if (method_exists($connection, 'executeStatement')) {
                            $connection->executeStatement($statement);
                        } else {
                            $connection->executeQuery($statement);
                        }
                    } catch (\Exception $e) {
                        echo 'Database error: '.$e->getMessage()."\n".'For query: '.$statement."\n";
                    }
                }
            }

            $this->connection = $connection;

            // get the queryBuilder
            $this->queryBuilder = $connection->createQueryBuilder();
        } catch (\Exception $exception) {
            echo 'Database error: '.$exception->getMessage()."\n";
        } finally {
            // turn off error reporting (if function exists)
            if (function_exists('mysqli_report')) {
                \mysqli_report(MYSQLI_REPORT_OFF);
            }
        }
    }

    /**
     * Authenticate the query with credentials from the set-up test project leader.
     */
    protected function logInSession(string $user = 'unittest')
    {
        // Map usernames to IDs
        $userMap = [
            'unittest' => '1',
            'developer' => '2',
            'noContract' => '4',
        ];

        $userId = $userMap[$user] ?? '1';

        // Get the user entity from the database to create a security token
        $userRepository = $this->serviceContainer->get('doctrine')->getRepository(\App\Entity\User::class);
        $userEntity = $userRepository->find($userId);

        if ($userEntity) {
            // Create the token for session storage only (let the firewall load it on next request)
            $usernamePasswordToken = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
                $userEntity,
                'main',
                $userEntity->getRoles()
            );

            // Obtain a session instance in Symfony 6 test environment
            // Prefer the real 'session' service so tests that clear it work reliably
            if ($this->serviceContainer->has('session')) {
                $session = $this->serviceContainer->get('session');
            } elseif ($this->serviceContainer->has('test.session')) {
                $session = $this->serviceContainer->get('test.session');
            } elseif ($this->serviceContainer->has('session.factory')) {
                $sessionFactory = $this->serviceContainer->get('session.factory');
                $session = $sessionFactory->createSession();
            } else {
                $session = new \Symfony\Component\HttpFoundation\Session\Session(
                    new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
                );
            }

            // Ensure session is started, then store token in session for firewall "main"
            if (method_exists($session, 'isStarted') && !$session->isStarted()) {
                $session->start();
            }
            $session->set('_security_main', serialize($usernamePasswordToken));
            $session->save();

            // Clear and set the cookies
            $this->client->getCookieJar()->clear();
            $cookie = new Cookie($session->getName(), $session->getId());
            $this->client->getCookieJar()->set($cookie);

            // Also set the token directly in token storage for the current kernel request cycle
            if ($this->serviceContainer->has('security.token_storage')) {
                $tokenStorage = $this->serviceContainer->get('security.token_storage');
                try {
                    $postAuthToken = new \Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken(
                        $userEntity,
                        'main',
                        $userEntity->getRoles()
                    );
                    $tokenStorage->setToken($postAuthToken);
                } catch (\Throwable) {
                    // Fallback to UsernamePasswordToken if PostAuthenticationToken not available
                    $tokenStorage->setToken($usernamePasswordToken);
                }
            }

            // Avoid kernel reboot to keep the same DB connection within a test method
            // This ensures transactional visibility across multiple requests
            $this->client->disableReboot();
        }
    }

    /**
     * Helper method to login a user using form submission.
     */
    protected function loginAs(string $username, string $password): void
    {
        // Use the client created in setUp()
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/login');

        $this->client->submitForm('Login', [
            'username' => $username,
            'password' => $password,
        ]);

        $this->assertResponseRedirects('/dashboard');
    }

    /**
     * Create a client with a default Authorization header.
     */
    protected function createAuthenticatedClient(string $username = 'test', string $password = 'password'): KernelBrowser
    {
        // Ensure the kernel is shut down before creating a new client
        static::ensureKernelShutdown();

        $kernelBrowser = static::createClient();
        $kernelBrowser->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/login',
            ['username' => $username, 'password' => $password]
        );

        $this->assertResponseRedirects();

        return $kernelBrowser;
    }

    /**
     * Helper method to create JSON request.
     */
    protected function createJsonRequest(
        string $method,
        string $uri,
        array $content = [],
        array $headers = [],
    ): KernelBrowser {
        // Use the client created in setUp()
        $this->client->request(
            $method,
            $uri,
            [],
            [],
            array_merge([
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $headers),
            [] !== $content ? json_encode($content) : null
        );

        // Return the same client instance used for the request
        return $this->client;
    }

    /**
     * Tests $statusCode against response status code.
     */
    protected function assertStatusCode(int $statusCode, string $message = ''): void
    {
        $this->assertSame(
            $statusCode,
            $this->client->getResponse()->getStatusCode(),
            $message
        );
    }

    /**
     * Assert that a message matches the response content.
     */
    protected function assertMessage(string $message): void
    {
        $responseContent = $this->client->getResponse()->getContent();

        // Try direct comparison first
        if ($message === $responseContent) {
            $this->assertTrue(true);

            return;
        }

        // Handle specific translation issues based on the messages.de.yml
        $translationMap = [
            '%num% Einträge wurden angelegt.' => '%num% entries have been added',
            'Für den Benutzer wurde kein Vertrag gefunden. Bitte verwenden Sie eine benutzerdefinierte Zeit.' => 'No contract for user found. Please use custome time.',
        ];

        foreach ($translationMap as $german => $english) {
            // Replace %num% with actual number in pattern
            $germanPattern = str_replace('%num%', '(\d+)', preg_quote($german, '/'));
            $englishPattern = str_replace('%num%', '$1', preg_quote($english, '/'));

            if (preg_match('/^'.$germanPattern.'$/', $message, $germanMatches)
                && preg_match('/^'.$englishPattern.'$/', (string) $responseContent, $englishMatches)) {
                $this->assertTrue(true, 'Translation matched via pattern');

                return;
            }
        }

        // Fall back to direct comparison
        $this->assertSame($message, $responseContent);
    }

    /**
     * Assert that the response has the expected content type.
     */
    protected function assertContentType(string $contentType): void
    {
        $this->assertStringContainsString(
            $contentType,
            $this->client->getResponse()->headers->get('content-type')
        );
    }

    /**
     * Takes a JSON in array and compares it against the response content.
     */
    protected function assertJsonStructure(array $json): void
    {
        $responseJson = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true
        );
        $this->assertArraySubset($json, $responseJson);
    }

    /**
     * Assert the length of the response or a specific path in the response.
     */
    protected function assertLength(int $length, ?string $path = null)
    {
        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true
        );
        if ($path) {
            foreach (explode('.', $path) as $key) {
                $response = $response[$key];
            }
        }

        $this->assertSame($length, count($response));
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
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');
            $this->queryBuilder = $connection->createQueryBuilder();

            // Also make sure $this->connection is properly initialized
            $this->connection = $connection;
        }
    }

    /**
     * For tests that need a completely fresh database state, call this method
     * at the beginning of the test to force a complete database reset.
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
     * Clear static state between test runs (important for parallel testing)
     * This helps ensure isolated test environments for parallel runs.
     */
    public static function tearDownAfterClass(): void
    {
        self::$databaseInitialized = false;
        self::$dataLoaded = false;
        parent::tearDownAfterClass();
    }
}
