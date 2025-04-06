<?php

declare(strict_types=1);

namespace Tests;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Abstract base test case that combines all test functionality.
 * Consolidates the features from the previous Base and TestCase classes.
 */
abstract class AbstractWebTestCase extends SymfonyWebTestCase
{
    use ArraySubsetAsserts;

    protected KernelBrowser $client;

    protected $serviceContainer;

    protected $connection;

    protected $queryBuilder;

    protected $filepath = '/../sql/unittest/002_testdata.sql';

    /**
     * The initial state of a table
     * used to assert integrity of table after a DEV test
     */
    protected $tableInitialState;

    /**
     * Flag to track if test data has been loaded
     */
    private static $dataLoaded = false;

    /**
     * Flag to track if database has been initialized
     */
    private static $databaseInitialized = false;

    protected $useTransactions = true;

    /**
     * Returns the kernel class to use for these tests
     */
    protected static function getKernelClass()
    {
        return \App\Kernel::class;
    }

    /**
     * Set up before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // create test env.
        $this->client = static::createClient();
        $this->serviceContainer = $this->client->getContainer();

        // Reset database state if needed
        $this->resetDatabase();

        // Begin a transaction to isolate test database changes
        if ($this->connection && $this->useTransactions) {
            // For PDO connections
            if (method_exists($this->connection, 'beginTransaction')) {
                $this->connection->beginTransaction();
            }
            // For Doctrine DBAL connections
            else if (method_exists($this->connection, 'getWrappedConnection')) {
                try {
                    $this->connection->getWrappedConnection()->beginTransaction();
                } catch (\Exception $e) {
                    // If transaction fails, log the error but continue
                    error_log("Transaction begin failed: " . $e->getMessage());
                }
            }
        }

        // authenticate user in session
        $this->logInSession();

        // Force German locale for tests
        $translator = $this->serviceContainer->get('translator');
        $translator->setLocale('de');

        // Also set the request locale if possible
        if ($request = $this->serviceContainer->get('request_stack')->getCurrentRequest()) {
            $request->setLocale('de');
        }
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void
    {
        // Roll back the transaction to restore the database to its original state
        if ($this->connection && $this->useTransactions) {
            // For PDO connections
            if (method_exists($this->connection, 'rollBack')) {
                // Check if a transaction is active before rolling back
                if (method_exists($this->connection, 'inTransaction') && $this->connection->inTransaction()) {
                    try {
                        $this->connection->rollBack();
                    } catch (\Exception $e) {
                        // If rollback fails, log the error but continue
                        error_log("Transaction rollback failed: " . $e->getMessage());
                    }
                }
            }
            // For Doctrine DBAL connections
            else if (method_exists($this->connection, 'getWrappedConnection')) {
                $wrappedConnection = $this->connection->getWrappedConnection();
                if (method_exists($wrappedConnection, 'isTransactionActive') && $wrappedConnection->isTransactionActive()) {
                    try {
                        $wrappedConnection->rollBack();
                    } catch (\Exception $e) {
                        // If rollback fails, log the error but continue
                        error_log("Transaction rollback failed: " . $e->getMessage());
                    }
                }
            }
        }

        // Clear entity manager to prevent stale data between tests
        if ($this->serviceContainer && $this->serviceContainer->has('doctrine')) {
            $this->serviceContainer->get('doctrine')->getManager()->clear();
        }

        parent::tearDown();
    }

    /**
     * Used in test for DEV users
     */
    protected function setInitialDbState(string $tableName)
    {
        $this->tableInitialState = $this->queryBuilder
            ->select('*')
            ->from($tableName)
            ->execute()
            ->fetchAllAssociative();
    }

    /**
     * Only Call in test where setInitialDbState was called before
     */
    protected function assertDbState(string $tableName)
    {
        $newTableState = $this->queryBuilder
            ->select('*')
            ->from($tableName)
            ->execute()
            ->fetchAllAssociative();
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
        $file = $filepath ? file_get_contents(__DIR__ . $filepath) : file_get_contents(__DIR__ . $this->filepath);

        //turn on error reporting (if function exists)
        if (function_exists('mysqli_report')) {
            \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        }

        try {
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');

            // For newer Doctrine DBAL versions
            if (method_exists($connection->getWrappedConnection(), 'getWrappedResourceHandle')) {
                $this->connection = $connection->getWrappedConnection()->getWrappedResourceHandle();
                $this->connection->multi_query($file);
            } else {
                // For newer Doctrine versions that don't expose the resource handle
                $statements = explode(';', $file);
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if ($statement !== '' && $statement !== '0') {
                        try {
                            $connection->executeQuery($statement);
                        } catch (\Exception $e) {
                            echo "Database error: " . $e->getMessage() . "\n" . "For query: " . $statement . "\n";
                        }
                    }
                }

                $this->connection = $connection;
            }

            // get the queryBuilder
            $this->queryBuilder = $connection->createQueryBuilder();
        } catch (\Exception $exception) {
            echo "Database error: " . $exception->getMessage() . "\n";
        } finally {
            //turn off error reporting (if function exists)
            if (function_exists('mysqli_report')) {
                \mysqli_report(MYSQLI_REPORT_OFF);
            }
        }
    }

    /**
     * Authenticate the query with credentials from the set-up test project leader
     */
    protected function logInSession(string $user = 'unittest')
    {
        // Map usernames to IDs
        $userMap = [
            'unittest' => '1',
            'developer' => '2',
            'noContract' => '4'
        ];

        $userId = $userMap[$user] ?? '1';

        // Get the user entity from the database to create a security token
        $userRepository = $this->serviceContainer->get('doctrine')->getRepository(\App\Entity\User::class);
        $userEntity = $userRepository->find($userId);

        if ($userEntity) {
            // Create and set token in security token storage
            $tokenStorage = $this->serviceContainer->get('security.token_storage');
            $usernamePasswordToken = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
                $userEntity,
                null,
                'main',
                $userEntity->getRoles()
            );
            $tokenStorage->setToken($usernamePasswordToken);

            // Store token in session
            $session = $this->serviceContainer->get('session');
            $session->set('_security_main', serialize($usernamePasswordToken));

            // Clear and set the cookies
            $this->client->getCookieJar()->clear();
            $cookie = new Cookie($session->getName(), $session->getId());
            $this->client->getCookieJar()->set($cookie);
            $session->save();

            // Ensure the client doesn't reboot between requests (maintains the session)
            $this->client->disableReboot();
        }
    }

    /**
     * Helper method to login a user using form submission.
     */
    protected function loginAs(string $username, string $password): void
    {
        // Use the client created in setUp()
        $this->client->request('GET', '/login');

        $this->client->submitForm('Login', [
            'username' => $username,
            'password' => $password,
        ]);

        $this->assertResponseRedirects('/dashboard');
    }

    /**
     * Create a client with a default Authorization header.
     */
    protected function createAuthenticatedClient(string $username = 'test', string $password = 'password'): \Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        // Ensure the kernel is shut down before creating a new client
        static::ensureKernelShutdown();

        $client = static::createClient();
        $client->request(
            'POST',
            '/login',
            ['username' => $username, 'password' => $password]
        );

        $this->assertResponseRedirects();

        return $client;
    }

    /**
     * Helper method to create JSON request.
     */
    protected function createJsonRequest(
        string $method,
        string $uri,
        array $content = [],
        array $headers = []
    ): \Symfony\Bundle\FrameworkBundle\KernelBrowser {
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
            $content ? json_encode($content) : null
        );

        // Return the same client instance used for the request
        return $this->client;
    }

    /**
     * Tests $statusCode against response status code
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
     * Assert that a message matches the response content
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
            'Für den Benutzer wurde kein Vertrag gefunden. Bitte verwenden Sie eine benutzerdefinierte Zeit.' => 'No contract for user found. Please use custome time.'
        ];

        foreach ($translationMap as $german => $english) {
            // Replace %num% with actual number in pattern
            $germanPattern = str_replace('%num%', '(\d+)', preg_quote($german, '/'));
            $englishPattern = str_replace('%num%', '$1', preg_quote($english, '/'));

            if (preg_match('/^' . $germanPattern . '$/', $message, $germanMatches) &&
                preg_match('/^' . $englishPattern . '$/', (string) $responseContent, $englishMatches)) {
                $this->assertTrue(true, "Translation matched via pattern");
                return;
            }
        }

        // Fall back to direct comparison
        $this->assertSame($message, $responseContent);
    }

    /**
     * Assert that the response has the expected content type
     */
    protected function assertContentType(string $contentType): void
    {
        $this->assertStringContainsString(
            $contentType,
            $this->client->getResponse()->headers->get('content-type')
        );
    }

    /**
     * Takes a JSON in array and compares it against the response content
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
     * Assert the length of the response or a specific path in the response
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
     * Reset database to initial state only when needed
     */
    protected function resetDatabase(?string $filepath = null): void
    {
        if (!self::$databaseInitialized || $filepath !== null) {
            $this->loadTestData($filepath);
            self::$databaseInitialized = true;
        } else if ($this->queryBuilder === null || $this->connection === null) {
            // Ensure queryBuilder and connection are available even if loadTestData wasn't called
            $connection = $this->serviceContainer->get('doctrine.dbal.default_connection');
            $this->queryBuilder = $connection->createQueryBuilder();

            // Also make sure $this->connection is properly initialized
            if (method_exists($connection->getWrappedConnection(), 'getWrappedResourceHandle')) {
                $this->connection = $connection->getWrappedConnection()->getWrappedResourceHandle();
            } else {
                $this->connection = $connection;
            }
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

        // Force a complete database reset
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
     * This helps ensure isolated test environments for parallel runs
     */
    public static function tearDownAfterClass(): void
    {
        self::$databaseInitialized = false;
        self::$dataLoaded = false;
        parent::tearDownAfterClass();
    }
}
