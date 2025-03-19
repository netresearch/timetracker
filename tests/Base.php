<?php

namespace Tests;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use DMS\PHPUnitExtensions\ArraySubset\ArraySubsetAsserts;

/**
 * TODO: When updating to PHPUnit > 8.x a new dependency is needed:
 * https://github.com/rdohms/phpunit-arraysubset-asserts
 */
abstract class Base extends WebTestCase
{
    use ArraySubsetAsserts;

    protected $client = null;
    protected $serviceContainer;
    protected $connection;
    protected $queryBuilder;
    protected $filepath = '/../sql/unittest/002_testdata.sql';

    /**
     * Returns the kernel class to use for these tests
     */
    protected static function getKernelClass()
    {
        return 'App\Kernel';
    }

    /**
     * The initial state of a table
     * used to assert integrity of table after a DEV test
     */
    protected $tableInitialState;

    /**
     * Used in test for DEV users
     */
    protected function setInitialDbState(string $tableName)
    {
        $this->tableInitialState = $this->queryBuilder
            ->select('*')
            ->from($tableName)
            ->execute()
            ->fetchAll();
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
            ->fetchAll();
        $this->assertSame($this->tableInitialState, $newTableState);
        $this->tableInitialState = null;
    }

    public function setUp(): void
    {
        // create test env.
        $this->client = static::createClient();
        $this->serviceContainer = $this->client->getContainer();
        // authenticate user in session
        $this->logInSession();
        // load test data
        #$this->loadTestData();
    }

    /**
     * Loads the test data specific for each test
     * Each test has a path to the file with the sql testdata
     * When executing loadTestData() the file from the $filepath
     * of current scope will be imported.
     */
    protected function loadTestData(string $filepath = null)
    {
        if (!$filepath) {
            $file = file_get_contents(dirname(__FILE__) . $this->filepath);
        } else {
            $file = file_get_contents(dirname(__FILE__) . $filepath);
        }

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
                while ($this->connection->next_result());
            } else {
                // For newer Doctrine versions that don't expose the resource handle
                $statements = explode(';', $file);
                foreach ($statements as $statement) {
                    $statement = trim($statement);
                    if (!empty($statement)) {
                        echo $statement . "\n";
                        $connection->executeQuery($statement);
                        echo "bar";
                    }
                }
                $this->connection = $connection;
            }

            //get the queryBuilder
            $this->queryBuilder = $connection->createQueryBuilder();
        } catch (\Exception $e) {
            echo "Database error: " . $e->getMessage() . "\n";
        } finally {
            //turn off error reporting (if function exists)
            if (function_exists('mysqli_report')) {
                \mysqli_report(MYSQLI_REPORT_OFF);
            }
        }
    }

    /**
     *  Authenticate the query with credentials from the set-up test projectlader
     */
    protected function logInSession(string $user = 'unittest')
    {
        $session = $this->serviceContainer->get('session');

        // Set the session values for backward compatibility
        $session->set('loggedIn', true);

        // Set user ID and type based on username
        $userId = '1';
        $userType = 'PL';

        if ($user == 'unittest') {
            $userId = '1';
        } elseif ($user == 'developer') {
            $userId = '2';
            $userType = 'DEV';
        } elseif ($user == 'noContract') {
            $userId = '4';
        }

        // Set session values expected by BaseController
        $session->set('loginId', $userId);
        $session->set('loginName', $user);
        $session->set('loginType', $userType);
        $session->set('loginTime', date('Y-m-d H:i:s'));

        // Get the user entity from the database to create a security token
        $userRepository = $this->serviceContainer->get('doctrine')->getRepository('App\Entity\User');
        $userEntity = $userRepository->find($userId);

        if ($userEntity) {
            // Create and set token in security token storage
            $tokenStorage = $this->serviceContainer->get('security.token_storage');
            $token = new \Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken(
                $userEntity,
                null,
                'main',
                ['ROLE_USER']
            );
            $tokenStorage->setToken($token);

            // Store token in session
            $session->set('_security_main', serialize($token));
        }

        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
        $session->save();
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

    protected function assertMessage(string $message): void
    {
        $this->assertSame($message, $this->client->getResponse()->getContent());
    }

    protected function assertContentType(string $contentType): void
    {
        $this->assertContains(
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
            $this->client->getResponse()->getContent(),
            true
        );
        $this->assertArraySubset($json, $responseJson);
    }

    protected function assertLength(int $length, string $path = null)
    {
        $response = json_decode(
            $this->client->getResponse()->getContent(),
            true
        );
        if ($path) {
            foreach (explode('.', $path) as $key) {
                $response = $response[$key];
            }
        }
        $this->assertSame($length, count($response));
    }
}
