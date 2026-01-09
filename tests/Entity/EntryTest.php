<?php

declare(strict_types=1);

namespace Tests\Entity;

use App\Entity\Account;
use App\Entity\Activity;
use App\Entity\Customer;
use App\Entity\Entry;
use App\Entity\Project;
use App\Entity\User;
use App\Enum\EntryClass;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
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
        $actualEnd = $entry->getEnd()->format('H:i');
        self::assertSame($invertedStart, $actualEnd, 'End should be clamped to start when end < start');
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
}
