<?php

namespace Tests;

use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * TODO: When updating to PHPUnit > 8.x a new dependency is needed:
 * https://github.com/rdohms/phpunit-arraysubset-asserts
 */
abstract class BaseTest extends WebTestCase
{
    protected $client = null;
    protected $container;
    protected $connection;
    protected $filepath = "/../sql/unittest/002_testdata.sql";

    public function setUp()
    {
        // create test env.
        $this->client = static::createClient();
        $this->container = $this->client->getContainer();
        // authenticate user in seassion
        $this->logInSession();
        //
        $this->loadTestData();
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
            $file = file_get_contents(dirname(__FILE__) .  $this->filepath);
        } else {
            $file = file_get_contents(dirname(__FILE__) .  $filepath);
        }
        //turn on error reporting
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->connection = $this->container
            ->get('doctrine.dbal.default_connection')
            ->getWrappedConnection()
            ->getWrappedResourceHandle();

        $this->connection->multi_query($file);
        while ($this->connection->next_result());
        //turn off error reporting
        mysqli_report(MYSQLI_REPORT_OFF);
    }

    /**
     *  Authenticate the query with credentials from the set-up test projectlader
     */
    protected function logInSession()
    {
        $session = $this->container->get('session');
        $session->set('loggedIn', true);
        $session->set('loginUsername', 'unittest');
        $session->set('loginId', '1');
        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
        $session->save();
    }

    /**
     * Tests $statusCode against response status code
     */
    protected function assertStatusCode(int $statusCode, string $message = ''): void
    {
        $this->assertSame($statusCode, $this->client->getResponse()->getStatusCode(), $message);
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
        $responseJson = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArraySubset($json, $responseJson);
    }
}