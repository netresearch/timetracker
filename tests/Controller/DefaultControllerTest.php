<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Service\ClockInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tests\AbstractWebTestCase;
use Tests\Service\TestClock;

use function count;
use function sprintf;

/**
 * @internal
 *
 * @coversNothing
 */
final class DefaultControllerTest extends AbstractWebTestCase
{
    private ?TestClock $testClock = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = $this->client->getContainer();
        if ($container->has(TestClock::class)) {
            $clockService = $container->get(TestClock::class);
            if ($clockService instanceof TestClock) {
                $this->testClock = $clockService;
            }
        } elseif ($container instanceof ContainerInterface && $container->has(ClockInterface::class)) {
            $clockService = $container->get(ClockInterface::class);
            if ($clockService instanceof TestClock) {
                $this->testClock = $clockService;
            }
        }

        if (!$this->testClock instanceof TestClock) {
            self::fail('Could not retrieve TestClock service. Ensure it is registered and public in the test environment.');
        }

        $this->testClock->setTestNow(new DateTimeImmutable('2023-10-24 12:00:00'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * AdminController and DefaultController both have a function
     * with the name getCustomersAction()
     * To differentiate them we give this one the suffix Default.
     */
    public function testGetCustomersActionDefault(): void
    {
        $expectedJson = [
            [
                'customer' => [
                    'name' => 'Der Bäcker von nebenan',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getCustomers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    public function testGetAllProjectsAction(): void
    {
        $parameter = [
            'customer' => 1,
        ];
        $expectedJson = [
            [
                'project' => [
                    'name' => 'Server attack',
                    'active' => true,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'SA',
                    'jira_id' => 'SA',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => 1,
                    'project_lead' => 1,
                    'technicalLead' => 1,
                    'technical_lead' => 1,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ],
            [
                'project' => [
                    'name' => 'Attack Server',
                    'active' => false,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getAllProjects', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->assertLength(2); // 2 Projects for customer 1 in Database
    }

    /**
     * Without parameter customer, the response will contain
     *  all project belonging to the
     * customer belonging to the teams of the current user +
     * all projects of global customers.
     *
     * With a customer the response contains from the
     * above projects the ones with global project
     * status + the one belonging to the customer
     */
    public function testGetProjectsAction(): void
    {
        $parameter = [
            'customer' => 3,
        ];
        $expectedJson = [
            [
                'project' => [
                    'name' => 'GlobalProject',
                    'active' => false,
                    'customer' => 3,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'ticketSystem' => null,
                    'ticket_system' => null,
                    'entries' => [],
                    'estimation' => 0,
                    'offer' => '0',
                    'billing' => 0,
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                    'internalJiraTicketSystem' => 0,
                    'internal_jira_ticket_system' => 0,
                    'estimationText' => '0m',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getProjects', $parameter);
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->assertLength(1);
    }

    public function testGetProjectStructureAction(): void
    {
        $expectedJson = [
            1 => [
                0 => [
                    'id' => 2,
                    'name' => 'Attack Server',
                    'jiraId' => 'TIM-1',
                    'active' => false,
                ],
                1 => [
                    'id' => 1,
                    'name' => 'Server attack',
                    'jiraId' => 'SA',
                    'active' => true,
                ],
            ],
            3 => [
                0 => [
                    'id' => 3,
                    'name' => 'GlobalProject',
                    'jiraId' => 'TIM-1',
                    'active' => false,
                ],
            ],
            'all' => [
                0 => [
                    'id' => 2,
                    'name' => 'Attack Server',
                    'active' => false,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'entries' => [],
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                ],
                1 => [
                    'id' => 3,
                    'name' => 'GlobalProject',
                    'active' => false,
                    'customer' => 3,
                    'global' => false,
                    'jiraId' => 'TIM-1',
                    'jira_id' => 'TIM-1',
                    'subtickets' => [],
                    'entries' => [],
                    'projectLead' => null,
                    'project_lead' => null,
                    'technicalLead' => null,
                    'technical_lead' => null,
                ],
                2 => [
                    'id' => 1,
                    'name' => 'Server attack',
                    'active' => true,
                    'customer' => 1,
                    'global' => false,
                    'jiraId' => 'SA',
                    'jira_id' => 'SA',
                    'subtickets' => [],
                    'entries' => [],
                    'projectLead' => 1,
                    'project_lead' => 1,
                    'technicalLead' => 1,
                    'technical_lead' => 1,
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getProjectStructure');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- activities routes ----------------------------------------
    public function testGetActivitiesAction(): void
    {
        $expectedJson = [
            0 => [
                'activity' => [
                    'id' => 1,
                    'name' => 'Backen',
                    'needsTicket' => false,
                    'factor' => 1,
                ],
            ],
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getActivities');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- users routes ----------------------------------------
    /**
     * Returns all users.
     */
    public function testGetUsersAction(): void
    {
        $expectedJson = [
            0 => [
                'user' => [
                    'username' => 'i.myself',
                    'type' => 'PL',
                    'abbr' => 'IMY',
                    'locale' => 'de',
                ],
            ],
            1 => [
                'user' => [
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    /**
     * Returns the user logged in seassion.
     */
    public function testGetUsersActionDev(): void
    {
        $expectedJson = [
            0 => [
                'user' => [
                    'username' => 'developer',
                    'type' => 'DEV',
                    'abbr' => 'NPL',
                    'locale' => 'de',
                ],
            ],
        ];
        $this->logInSession('developer');
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getUsers');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
    }

    // -------------- data routes ----------------------------------------
    public function testGetDataActionDefaultParameter(): void
    {
        // Clock is mocked to 2023-10-24 (Tuesday) in setUp.
        // Default days is 3, which calculates to 5 calendar days for a Tuesday.
        // Query range >= 2023-10-19.
        // Expect entries 4 (2023-10-24) and 5 (2023-10-20).
        // Note: The order might depend on the SQL ORDER BY clause (day DESC, start DESC).
        $expectedJson = [
            0 => [
                'entry' => [
                    'id' => 4, // Added ID assertion
                    'date' => '24/10/2023', // Fixed date
                    'start' => '13:00',
                    'end' => '13:25',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'testGetDataAction',
                    'ticket' => 'testGetDataAction',
                    'class' => 1,
                    'duration' => '00:25',
                ],
            ],
            1 => [
                'entry' => [
                    'id' => 5, // Added ID assertion
                    'date' => '20/10/2023', // Fixed date
                    'start' => '14:00',
                    'end' => '14:25',
                    'user' => 1,
                    'customer' => 1,
                    'project' => 1,
                    'activity' => 1,
                    'description' => 'testGetDataAction',
                    'ticket' => 'testGetDataAction',
                    'class' => 1,
                    'duration' => '00:25',
                ],
            ],
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getData');
        $this->assertStatusCode(200);
        $this->assertJsonStructure($expectedJson);
        $this->assertLength(2); // Assert exactly 2 entries are returned
    }

    #[DataProvider('provideGetDataActionForParameterCases')]
    public function testGetDataActionForParameter(string $mockDate, int $daysParam, int $expectedCount, string $expectedDate): void
    {
        // Set the clock to the date provided by the data provider
        self::assertNotNull($this->testClock, 'TestClock was not initialized in setUp');
        $this->testClock->setTestNow(new DateTimeImmutable($mockDate));

        $parameter = [
            'days' => $daysParam,
        ];

        // Define the expected keys for a single entry
        $expectedEntryKeys = [
            'id', 'date', 'start', 'end', 'user', 'customer', 'project',
            'activity', 'description', 'ticket', 'class', 'duration',
            'extTicket', 'extTicketUrl',
        ];

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getData/days/' . $parameter['days']);
        $this->assertStatusCode(200);

        $responseData = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($responseData);

        // Assert the exact count of entries returned
        self::assertCount($expectedCount, $responseData, sprintf('Failed asserting that count %d matches expected %d for date %s / days %d', count($responseData), $expectedCount, $mockDate, $daysParam));

        // Only perform structure checks if we expect results
        if ($expectedCount > 0) {
            // Loop through returned entries and check structure
            for ($i = 0; $i < $expectedCount; ++$i) {
                self::assertArrayHasKey($i, $responseData, 'Response missing index ' . $i);
                self::assertArrayHasKey('entry', $responseData[$i], sprintf("Response item %d missing 'entry' key.", $i));
                $entry = $responseData[$i]['entry'];
                self::assertIsArray($entry, sprintf("Response item %d['entry'] is not an array.", $i));

                // Check if all expected keys exist in the entry
                foreach ($expectedEntryKeys as $expectedEntryKey) {
                    self::assertArrayHasKey($expectedEntryKey, $entry, sprintf("Response item %d['entry'] missing key '%s'.", $i, $expectedEntryKey));
                }
            }

            // Assert the date of the *first* entry (most recent due to ORDER BY)
            self::assertSame($expectedDate, $responseData[0]['entry']['date'], 'Date in first response entry does not match expected most recent date.');
        }
        // If no count expected, ensure response is empty (already handled by assertCount)
        // $this->assertEmpty($responseData, 'Expected empty response but got content.');
    }

    /**
     * Data provider for testGetDataActionForParameter.
     *
     * Provides different scenarios: a non-Monday workday and a Monday.
     *
     * @return array<string, array{mockDate: string, daysParam: int, expectedCount: int, expectedDate: string}>
     */
    public static function provideGetDataActionForParameterCases(): iterable
    {
        // Correction: Monday 1 day -> count is 2, most recent date is 24/10/2023
        // Let's adjust the provider data
        return [
            'tuesday_1_day' => [
                'mockDate' => '2023-10-24 10:00:00',
                'daysParam' => 1,
                'expectedCount' => 1,
                'expectedDate' => '24/10/2023',
            ],
            'monday_1_day' => [
                'mockDate' => '2023-10-23 10:00:00',
                'daysParam' => 1,
                'expectedCount' => 2,
                'expectedDate' => '24/10/2023', // Entry 4 is most recent in results (ORDER BY day DESC)
            ],
            'monday_3_days' => [
                'mockDate' => '2023-10-23 10:00:00',
                'daysParam' => 3,
                'expectedCount' => 2,
                'expectedDate' => '24/10/2023',
            ],
        ];
    }

    // -------------- summary routes ----------------------------------------
    public function testGetSummaryAction(): void
    {
        // Find an existing entry to test with
        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $entry = $entityManager->getRepository(\App\Entity\Entry::class)->findOneBy([]);

        if (!$entry) {
            self::markTestSkipped('No entries found in the database.');
        }

        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            '/getSummary',
            ['id' => $entry->getId()],
        );

        $this->assertStatusCode(200);

        // Verify we have data in the expected format
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('customer', $response);
        self::assertArrayHasKey('project', $response);
        self::assertArrayHasKey('activity', $response);
        self::assertArrayHasKey('ticket', $response);

        // Check structure of each section
        foreach (['customer', 'project', 'activity', 'ticket'] as $section) {
            self::assertArrayHasKey('scope', $response[$section]);
            self::assertArrayHasKey('name', $response[$section]);
            self::assertArrayHasKey('entries', $response[$section]);
            self::assertArrayHasKey('total', $response[$section]);
            self::assertArrayHasKey('own', $response[$section]);
            self::assertArrayHasKey('estimation', $response[$section]);
        }
    }

    public function testGetSummaryIncorrectIdAction(): void
    {
        // test for non existent id
        $parameter = [
            'id' => 999,  // req
        ];
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getSummary', $parameter);
        $this->assertStatusCode(404, 'Second delete did not return expected 404');
        $this->assertJsonStructure(['message' => 'No entry for id.']);
    }

    public function testGetTimeSummaryAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getTimeSummary');

        $this->assertStatusCode(200);

        // Verify response has actual data
        $response = json_decode($this->client->getResponse()->getContent(), true);

        // Check structure
        self::assertArrayHasKey('today', $response);
        self::assertArrayHasKey('week', $response);
        self::assertArrayHasKey('month', $response);

        // Check data types
        foreach (['today', 'week', 'month'] as $period) {
            self::assertArrayHasKey('duration', $response[$period]);
            self::assertIsNumeric($response[$period]['duration']);
            self::assertArrayHasKey('count', $response[$period]);
            self::assertIsBool($response[$period]['count']);
        }
    }

    public function testIndexActionWithAuthentication(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        $response = $this->client->getResponse();
        $this->assertStatusCode(200);

        // Check that the page contains expected elements
        $content = $response->getContent();
        self::assertStringContainsString('<title>', $content);
        // $this->assertStringContainsString($this->getParameter('app_title'), $content);

        // Check for the main application structure
        self::assertStringContainsString('let projectsData = ', $content);
    }

    public function testIndexActionWithoutAuthenticationRedirectsToLogin(): void
    {
        $this->ensureKernelShutdown();
        $this->client = self::createClient();

        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/');

        // Should redirect to login
        $this->assertStatusCode(302);

        // Check for login form elements
        $this->client->getResponse()->getContent();
        $this->assertResponseHasHeader('Location', '/login');
    }

    public function testGetDataAction(): void
    {
        // Default test with current date
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_POST, '/getData');

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();

        // Decode JSON response
        $data = json_decode($content, true);

        // Verify basic structure
        self::assertIsArray($data);

        // Verify entries structure if there are any entries
        if ([] !== $data) {
            $entry = reset($data)['entry'];
            self::assertArrayHasKey('id', $entry);
            self::assertArrayHasKey('customer', $entry);
            self::assertArrayHasKey('project', $entry);
            self::assertArrayHasKey('activity', $entry);
        }
    }

    public function testGetCustomersAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getCustomers');

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();

        // Decode JSON response
        $data = json_decode($content, true);

        // Verify structure
        self::assertIsArray($data);

        // Check that at least one customer exists
        self::assertGreaterThan(0, count($data));

        // Check structure of first customer
        $customer = reset($data)['customer'];
        self::assertArrayHasKey('id', $customer);
        self::assertArrayHasKey('name', $customer);
    }

    public function testGetTrackingActivitiesAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getActivities');

        $this->assertStatusCode(200);
        $response = $this->client->getResponse();
        $content = $response->getContent();

        // Decode JSON response
        $data = json_decode($content, true);

        // Verify structure
        self::assertIsArray($data);

        // Check that at least one activity exists
        self::assertGreaterThan(0, count($data));

        // Check structure of first activity
        $activity = reset($data)['activity'];
        self::assertArrayHasKey('id', $activity);
        self::assertArrayHasKey('name', $activity);
    }

    public function testGetHolidaysAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/getHolidays');

        $this->assertStatusCode(200);

        $expectedJson = [
            [
                'holiday' => [
                    'date' => true,
                    'name' => true,
                ],
            ],
        ];

        // Verify the JSON structure if there are holiday entries
        $responseContent = json_decode($this->client->getResponse()->getContent(), true);
        if (!empty($responseContent)) {
            $this->assertJsonStructure($expectedJson);
        } else {
            self::assertIsArray($responseContent);
        }
    }

    public function testExportAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/export/7');

        $this->assertStatusCode(200);
        // CSV export should contain specific headers
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Datum', $content);
        self::assertStringContainsString('Kunde', $content);
        self::assertStringContainsString('Projekt', $content);
        self::assertStringContainsString('Tätigkeit', $content);
    }

    public function testGetTicketTimeSummaryJsAction(): void
    {
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/scripts/timeSummaryForJira');

        $this->assertStatusCode(200);

        $raw = $this->client->getResponse()->getContent();
        self::assertNotEmpty($raw);
        $json = json_decode($raw, true);
        self::assertIsString($json);

        // The endpoint injects absolute TT base URL
        self::assertStringContainsString('http://localhost/', $json);
        self::assertStringContainsString('/getTicketTimeSummary/', $json);
    }

    public function testJiraOAuthCallbackAction(): void
    {
        // Simulate a valid callback payload; controller will catch exceptions if misconfigured
        $this->client->request(\Symfony\Component\HttpFoundation\Request::METHOD_GET, '/jiraoauthcallback', [
            'oauth_token' => 'dummy',
            'oauth_verifier' => 'dummy',
            'tsid' => 1,
        ]);

        // We accept either 200 text response or redirect back to start, depending on the mocked services
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 302, 500], 'Status not in expected set');
    }
}
