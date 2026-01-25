<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Account;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\TicketSystem;
use App\Entity\User;
use App\Enum\EntryClass;
use DateTime;
use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Entry entity.
 *
 * @internal
 */
#[CoversClass(Entry::class)]
final class EntryTest extends TestCase
{
    public function testGetterSetter(): void
    {
        $entry = new Entry();

        // test account
        self::assertNull($entry->getAccount());
        self::assertSame(0, $entry->getAccountId());
        $account = new Account();
        $account->setId(6);

        $entry->setAccount($account);
        self::assertSame($account, $entry->getAccount());
        self::assertSame(6, $entry->getAccountId());

        // test duration
        $entry->setDuration(95);
        self::assertSame(95, $entry->getDuration());

        // test ticket
        $entry->setTicket('ABCDE-12345678');
        self::assertSame('ABCDE-12345678', $entry->getTicket());

        // test class
        $entry->setClass(EntryClass::OVERLAP);
        self::assertSame(EntryClass::OVERLAP, $entry->getClass());

        // test user
        self::assertNull($entry->getUser());
        self::assertSame(0, $entry->getUserId());
        $user = new User();
        $user->setId(14);

        $entry->setUser($user);
        self::assertSame($user, $entry->getUser());
        self::assertSame(14, $entry->getUserId());

        // test project
        self::assertNull($entry->getProject());
        self::assertSame(0, $entry->getProjectId());
        $project = new Project();
        $project->setId(33);

        $entry->setProject($project);
        self::assertSame($project, $entry->getProject());
        self::assertSame(33, $entry->getProjectId());

        // test customer
        self::assertNull($entry->getCustomer());
        self::assertSame(0, $entry->getCustomerId());
        $customer = new Customer();
        $customer->setId(42);

        $entry->setCustomer($customer);
        self::assertSame($customer, $entry->getCustomer());
        self::assertSame(42, $entry->getCustomerId());

        // test customer
        self::assertNull($entry->getActivity());
        self::assertSame(0, $entry->getActivityId());
        $activity = new Activity();
        $activity->setId(51);

        $entry->setActivity($activity);
        self::assertSame($activity, $entry->getActivity());
        self::assertSame(51, $entry->getActivityId());

        // test worklog
        $entry->setWorklogId(27);
        self::assertSame(27, $entry->getWorklogId());
    }

    public function testSetStart(): void
    {
        $day = '2011-11-11';
        $givenStart = '13:30';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($givenStart);

        $expected = $day . ' ' . $givenStart;
        $start = $entry->getStart()->format('Y-m-d H:i');
        self::assertSame($expected, $start, 'Got start ' . $start);
    }

    public function testSetEnd(): void
    {
        $day = '2011-11-11';
        $givenEnd = '13:30';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setEnd($givenEnd);

        $expected = $day . ' ' . $givenEnd;
        $end = $entry->getEnd()->format('Y-m-d H:i');
        self::assertSame($expected, $end, 'Got end ' . $end);
    }

    public function testInvertedTimes(): void
    {
        $day = '2011-11-11';
        $start = '11:11';
        $end = '22:22';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        self::assertSame($start, $entry->getStart()->format('H:i'), 'Start and end should not invert');

        // Test inverted times: when start > end, end should be clamped to start
        $invertedStart = '22:22';
        $invertedEnd = '11:11';
        $entry->setStart($invertedStart);
        $entry->setEnd($invertedEnd);
        self::assertSame($invertedStart, $entry->getStart()->format('H:i'), 'Start should remain as set');
        // When end < start, the Entry entity clamps end to equal start
        // Use expected value directly to avoid PHPStan type narrowing issues
        $expectedEnd = '22:22';
        $actualEnd = $entry->getEnd()->format('H:i');
        self::assertSame($expectedEnd, $actualEnd, 'End should be clamped to start when end < start');
    }

    public function testCalcDuration(): void
    {
        $day = '2011-11-11';
        $start = '11:11';
        $end = '21:33';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->calcDuration(1);
        self::assertSame(0, $entry->getDuration());

        $entry->setEnd($end);
        $entry->calcDuration(1);
        self::assertSame(622, $entry->getDuration());
        $entry->calcDuration(0.5);
        self::assertSame(311, $entry->getDuration());
    }

    public function testNullDurationException(): void
    {
        $day = '2011-11-11';
        $start = '22:22';
        $end = '11:11';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Duration must be greater than 0!');
        $entry->validateDuration();
    }

    public function testToArray(): void
    {
        $entry = new Entry();

        // empty case
        $result = $entry->toArray();
        self::assertNull($result['customer']);
        self::assertNull($result['project']);

        // full case
        $customer = new Customer();
        $customer->setId(17);

        $project = new Project();
        $project->setId(21);
        $project->setCustomer($customer);

        // test simple case without customer and project
        $entry
            ->setId(5)
            ->setDescription('foo')
            ->setTicket('TTT-51');
        $result = $entry->toArray();
        self::assertSame(5, $result['id']);
        self::assertSame('foo', $result['description']);
        self::assertSame('TTT-51', $result['ticket']);
        self::assertNull($result['customer']);
        self::assertNull($result['project']);

        // test indirect getCustomerId call
        $entry
            ->setProject($project);
        $result = $entry->toArray();
        self::assertSame(17, $result['customer']);
        self::assertSame(21, $result['project']);

        // test project and customer
        $entry
            ->setCustomer($customer)
            ->setProject($project);
        $result = $entry->toArray();
        self::assertSame(17, $result['customer']);
        self::assertSame(21, $result['project']);
    }

    /**
     * Regression test: duration must be returned as formatted string (H:i) for ExtJS model compatibility.
     * The ExtJS Entry model expects duration with type: 'date', dateFormat: 'H:i'.
     *
     * @see assets/js/netresearch/model/Entry.js - {name: 'duration', type: 'date', dateFormat: 'H:i'}
     */
    public function testToArrayDurationFormat(): void
    {
        $entry = new Entry();

        // Test zero duration
        $entry->setDuration(0);
        $result = $entry->toArray();
        self::assertSame('00:00', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(0, $result['durationMinutes'], 'durationMinutes must be integer');

        // Test 30 minutes
        $entry->setDuration(30);
        $result = $entry->toArray();
        self::assertSame('00:30', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(30, $result['durationMinutes'], 'durationMinutes must be integer');

        // Test 1 hour (60 minutes)
        $entry->setDuration(60);
        $result = $entry->toArray();
        self::assertSame('01:00', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(60, $result['durationMinutes'], 'durationMinutes must be integer');

        // Test 8 hours (480 minutes) - typical workday
        $entry->setDuration(480);
        $result = $entry->toArray();
        self::assertSame('08:00', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(480, $result['durationMinutes'], 'durationMinutes must be integer');

        // Test 2 hours 50 minutes (170 minutes)
        $entry->setDuration(170);
        $result = $entry->toArray();
        self::assertSame('02:50', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(170, $result['durationMinutes'], 'durationMinutes must be integer');

        // Test large duration: 10+ hours
        $entry->setDuration(625); // 10:25
        $result = $entry->toArray();
        self::assertSame('10:25', $result['duration'], 'duration must be formatted string H:i');
        self::assertSame(625, $result['durationMinutes'], 'durationMinutes must be integer');
    }

    // ==================== Billable tests ====================

    public function testBillableIsNullByDefault(): void
    {
        $entry = new Entry();

        self::assertNull($entry->getBillable());
    }

    public function testSetBillableReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->setBillable(true);

        self::assertSame($entry, $result);
        self::assertTrue($entry->getBillable());
    }

    public function testSetBillableToFalse(): void
    {
        $entry = new Entry();

        $entry->setBillable(false);

        self::assertFalse($entry->getBillable());
    }

    // ==================== TicketTitle tests ====================

    public function testTicketTitleIsNullByDefault(): void
    {
        $entry = new Entry();

        self::assertNull($entry->getTicketTitle());
    }

    public function testSetTicketTitleReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->setTicketTitle('Fix the bug');

        self::assertSame($entry, $result);
        self::assertSame('Fix the bug', $entry->getTicketTitle());
    }

    public function testSetTicketTitleToNull(): void
    {
        $entry = new Entry();
        $entry->setTicketTitle('Some title');

        $entry->setTicketTitle(null);

        self::assertNull($entry->getTicketTitle());
    }

    // ==================== Ticket with spaces tests ====================

    public function testSetTicketStripsSpaces(): void
    {
        $entry = new Entry();

        $entry->setTicket('PROJ 123 ABC');

        self::assertSame('PROJ123ABC', $entry->getTicket());
    }

    // ==================== Description tests ====================

    public function testDescriptionIsEmptyByDefault(): void
    {
        $entry = new Entry();

        self::assertSame('', $entry->getDescription());
    }

    public function testSetDescriptionReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->setDescription('Working on feature');

        self::assertSame($entry, $result);
        self::assertSame('Working on feature', $entry->getDescription());
    }

    // ==================== Day with different types tests ====================

    public function testSetDayWithDateTimeImmutable(): void
    {
        $entry = new Entry();
        $date = new DateTimeImmutable('2025-03-10');

        $entry->setDay($date);

        self::assertSame('2025-03-10', $entry->getDay()->format('Y-m-d'));
    }

    public function testSetDayWithDateTime(): void
    {
        $entry = new Entry();
        $date = new DateTime('2025-01-15');

        $result = $entry->setDay($date);

        self::assertSame($entry, $result);
        self::assertSame('2025-01-15', $entry->getDay()->format('Y-m-d'));
    }

    public function testSetStartWithDateTime(): void
    {
        $entry = new Entry();
        $entry->setDay('2025-01-15');
        $start = new DateTime('2025-01-15 09:00:00');

        $result = $entry->setStart($start);

        self::assertSame($entry, $result);
        self::assertSame('09:00', $entry->getStart()->format('H:i'));
    }

    public function testSetEndWithDateTime(): void
    {
        $entry = new Entry();
        $entry->setDay('2025-01-15');
        $end = new DateTime('2025-01-15 17:00:00');

        $result = $entry->setEnd($end);

        self::assertSame($entry, $result);
        self::assertSame('17:00', $entry->getEnd()->format('H:i'));
    }

    // ==================== getDurationString tests ====================

    public function testGetDurationStringZero(): void
    {
        $entry = new Entry();
        $entry->setDuration(0);

        self::assertSame('00:00', $entry->getDurationString());
    }

    public function testGetDurationStringLargeValue(): void
    {
        $entry = new Entry();
        $entry->setDuration(600); // 600 minutes = 10:00

        self::assertSame('10:00', $entry->getDurationString());
    }

    // ==================== Class/EntryClass tests ====================

    public function testClassIsPlainByDefault(): void
    {
        $entry = new Entry();

        self::assertSame(EntryClass::PLAIN, $entry->getClass());
    }

    public function testAddClassReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->addClass(EntryClass::DAYBREAK);

        self::assertSame($entry, $result);
        self::assertSame(EntryClass::DAYBREAK, $entry->getClass());
    }

    // ==================== External data tests ====================

    public function testExternalSummaryIsEmptyByDefault(): void
    {
        $entry = new Entry();

        self::assertSame('', $entry->getExternalSummary());
    }

    public function testSetExternalSummary(): void
    {
        $entry = new Entry();

        $entry->setExternalSummary('Bug fix summary');

        self::assertSame('Bug fix summary', $entry->getExternalSummary());
    }

    public function testExternalReporterIsEmptyByDefault(): void
    {
        $entry = new Entry();

        self::assertSame('', $entry->getExternalReporter());
    }

    public function testSetExternalReporter(): void
    {
        $entry = new Entry();

        $entry->setExternalReporter('john.doe@example.com');

        self::assertSame('john.doe@example.com', $entry->getExternalReporter());
    }

    public function testExternalLabelsIsEmptyByDefault(): void
    {
        $entry = new Entry();

        self::assertSame([], $entry->getExternalLabels());
    }

    public function testSetExternalLabels(): void
    {
        $entry = new Entry();

        $labels = ['bug', 'urgent', 'backend'];
        $entry->setExternalLabels($labels);

        self::assertSame($labels, $entry->getExternalLabels());
    }

    // ==================== Internal Jira ticket tests ====================

    public function testInternalJiraTicketOriginalKeyIsNullByDefault(): void
    {
        $entry = new Entry();

        self::assertNull($entry->getInternalJiraTicketOriginalKey());
    }

    public function testSetInternalJiraTicketOriginalKeyReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->setInternalJiraTicketOriginalKey('TYPO-1234');

        self::assertSame($entry, $result);
        self::assertSame('TYPO-1234', $entry->getInternalJiraTicketOriginalKey());
    }

    public function testHasInternalJiraTicketOriginalKeyReturnsFalseWhenNull(): void
    {
        $entry = new Entry();

        self::assertFalse($entry->hasInternalJiraTicketOriginalKey());
    }

    public function testHasInternalJiraTicketOriginalKeyReturnsFalseWhenEmpty(): void
    {
        $entry = new Entry();
        $entry->setInternalJiraTicketOriginalKey('');

        self::assertFalse($entry->hasInternalJiraTicketOriginalKey());
    }

    public function testHasInternalJiraTicketOriginalKeyReturnsTrueWhenSet(): void
    {
        $entry = new Entry();
        $entry->setInternalJiraTicketOriginalKey('PROJ-123');

        self::assertTrue($entry->hasInternalJiraTicketOriginalKey());
    }

    // ==================== SyncedToTicketsystem tests ====================

    public function testSyncedToTicketsystemIsFalseByDefault(): void
    {
        $entry = new Entry();

        self::assertFalse($entry->getSyncedToTicketsystem());
    }

    public function testSetSyncedToTicketsystemReturnsFluentInterface(): void
    {
        $entry = new Entry();

        $result = $entry->setSyncedToTicketsystem(true);

        self::assertSame($entry, $result);
        self::assertTrue($entry->getSyncedToTicketsystem());
    }

    // ==================== getTicketSystemIssueLink tests ====================

    public function testGetTicketSystemIssueLinkReturnsTicketWhenNoProject(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-123');

        self::assertSame('PROJ-123', $entry->getTicketSystemIssueLink());
    }

    public function testGetTicketSystemIssueLinkReturnsTicketWhenNoTicketSystem(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-123');
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn(null);
        $entry->setProject($project);

        self::assertSame('PROJ-123', $entry->getTicketSystemIssueLink());
    }

    public function testGetTicketSystemIssueLinkReturnsTicketWhenEmptyTicketUrl(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-123');
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getTicketUrl')->willReturn('');
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $entry->setProject($project);

        self::assertSame('PROJ-123', $entry->getTicketSystemIssueLink());
    }

    public function testGetTicketSystemIssueLinkReturnsTicketWhenNullTicketUrl(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-123');
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getTicketUrl')->willReturn(null);
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $entry->setProject($project);

        self::assertSame('PROJ-123', $entry->getTicketSystemIssueLink());
    }

    public function testGetTicketSystemIssueLinkReturnsFormattedUrl(): void
    {
        $entry = new Entry();
        $entry->setTicket('PROJ-123');
        $ticketSystem = $this->createMock(TicketSystem::class);
        $ticketSystem->method('getTicketUrl')->willReturn('https://jira.example.com/browse/%s');
        $project = $this->createMock(Project::class);
        $project->method('getTicketSystem')->willReturn($ticketSystem);
        $entry->setProject($project);

        self::assertSame('https://jira.example.com/browse/PROJ-123', $entry->getTicketSystemIssueLink());
    }

    // ==================== getPostDataForInternalJiraTicketCreation tests ====================

    public function testGetPostDataForInternalJiraTicketCreationWithoutProject(): void
    {
        $entry = new Entry();
        $entry->setTicket('EXT-456');

        $result = $entry->getPostDataForInternalJiraTicketCreation();

        self::assertSame('', $result['fields']['project']['key']);
        self::assertSame('EXT-456', $result['fields']['summary']);
        self::assertSame('Task', $result['fields']['issuetype']['name']);
    }

    public function testGetPostDataForInternalJiraTicketCreationWithProject(): void
    {
        $entry = new Entry();
        $entry->setTicket('EXT-789');
        $project = $this->createMock(Project::class);
        $project->method('getInternalJiraProjectKey')->willReturn('INT');
        $project->method('getTicketSystem')->willReturn(null);
        $entry->setProject($project);

        $result = $entry->getPostDataForInternalJiraTicketCreation();

        self::assertSame('INT', $result['fields']['project']['key']);
        self::assertSame('EXT-789', $result['fields']['summary']);
        self::assertSame('Task', $result['fields']['issuetype']['name']);
    }

    // ==================== toArray with worklog and extTicket tests ====================

    public function testToArrayWithWorklogAndExtTicket(): void
    {
        $entry = new Entry();
        $entry->setId(42);
        $entry->setDay('2025-01-15');
        $entry->setStart('09:00');
        $entry->setEnd('17:00');
        $entry->setTicket('PROJ-123');
        $entry->setDescription('Full day work');
        $entry->setDuration(480);
        $entry->setWorklogId(99999);
        $entry->setInternalJiraTicketOriginalKey('EXT-456');
        $entry->setClass(EntryClass::PLAIN);

        $user = new User();
        $user->setId(10);
        $entry->setUser($user);

        $activity = new Activity();
        $activity->setId(40);
        $entry->setActivity($activity);

        $result = $entry->toArray();

        self::assertSame(42, $result['id']);
        self::assertSame('15/01/2025', $result['date']);
        self::assertSame('09:00', $result['start']);
        self::assertSame('17:00', $result['end']);
        self::assertSame(10, $result['user']);
        self::assertSame(40, $result['activity']);
        self::assertSame('Full day work', $result['description']);
        self::assertSame('PROJ-123', $result['ticket']);
        self::assertSame('08:00', $result['duration']);
        self::assertSame(480, $result['durationMinutes']);
        self::assertSame(1, $result['class']);
        self::assertSame(99999, $result['worklog']);
        self::assertSame('EXT-456', $result['extTicket']);
    }

    // ==================== validateDuration tests ====================

    public function testValidateDurationPassesWhenEndAfterStart(): void
    {
        $entry = new Entry();
        $entry->setDay('2025-01-15');
        $entry->setStart('09:00');
        $entry->setEnd('10:00');

        $result = $entry->validateDuration();

        self::assertSame($entry, $result);
    }
}
