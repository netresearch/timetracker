<?php declare(strict_types=1);

namespace App\Tests\Entity;

use Exception;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use App\Entity\Entry;
use App\Entity\User;
use App\Entity\Project;
use App\Entity\Account;
use App\Entity\Customer;
use App\Entity\Activity;

class EntryTest extends TestCase
{
    public function testGetterSetter(): void
    {
        $entry = new Entry();

        // test account
        static::assertNull($entry->getAccount());
        static::assertNull($entry->getAccountId());
        $account = new Account();
        $account->setId(6);
        $entry->setAccount($account);
        static::assertSame($account, $entry->getAccount());
        static::assertSame(6, $entry->getAccountId());

        // test duration
        $entry->setDuration(95);
        static::assertSame(95, $entry->getDuration());

        // test ticket
        $entry->setTicket('ABCDE-12345678');
        static::assertSame('ABCDE-12345678', $entry->getTicket());

        // test class 
        $entry->setClass(Entry::CLASS_OVERLAP);
        static::assertSame(Entry::CLASS_OVERLAP, $entry->getClass());

        // test user
        static::assertNull($entry->getUser());
        static::assertNull($entry->getUserId());
        $user = new User();
        $user->setId(14);
        $entry->setUser($user);
        static::assertSame($user, $entry->getUser());
        static::assertSame(14, $entry->getUserId());

        // test project
        static::assertNull($entry->getProject());
        static::assertNull($entry->getProjectId());
        $project = new Project();
        $project->setId(33);
        $entry->setProject($project);
        static::assertSame($project, $entry->getProject());
        static::assertSame(33, $entry->getProjectId());

        // test customer
        static::assertNull($entry->getCustomer());
        static::assertNull($entry->getCustomerId());
        $customer = new Customer();
        $customer->setId(42);
        $entry->setCustomer($customer);
        static::assertSame($customer, $entry->getCustomer());
        static::assertSame(42, $entry->getCustomerId());

        // test customer
        static::assertNull($entry->getActivity());
        static::assertNull($entry->getActivityId());
        $activity = new Activity();
        $activity->setId(51);
        $entry->setActivity($activity);
        static::assertSame($activity, $entry->getActivity());
        static::assertSame(51, $entry->getActivityId());

        // test worklog
        $entry->setWorklogId(27);
        static::assertSame(27, $entry->getWorklogId());
    }

    public function testSetStart(): void
    {
        $day        = '2011-11-11';
        $givenStart = '13:30';
        $entry      = new Entry();
        $entry->setDay($day);
        $entry->setStart($givenStart);
        $expected = $day . ' ' . $givenStart;
        $start    = $entry->getStart()->format('Y-m-d H:i');
        static::assertSame($expected, $start, 'Got start ' . $start);
    }

    public function testSetEnd(): void
    {
        $day      = '2011-11-11';
        $givenEnd = '13:30';
        $entry    = new Entry();
        $entry->setDay($day);
        $entry->setEnd($givenEnd);
        $expected = $day . ' ' . $givenEnd;
        $end      = $entry->getEnd()->format('Y-m-d H:i');
        static::assertSame($expected, $end, 'Got end ' . $end);
    }

    public function testInvertedTimes(): void
    {
        $day   = '2011-11-11';
        $start = '11:11';
        $end   = '22:22';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        static::assertSame($start, $entry->getStart()->format('H:i'), 'Start and end should not invert');

        $start = '22:22';
        $end   = '11:11';
        $entry->setStart($start);
        $entry->setEnd($end);
        static::assertSame($start, $entry->getStart()->format('H:i'), 'End should be greater or equal start');
        static::assertSame($start, $entry->getEnd()->format('H:i'), 'End should be greater or equal start');

    }

    public function testCalcDuration(): void
    {
        $day   = '2011-11-11';
        $start = '11:11';
        $end   = '21:33';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->calcDuration(1);
        static::assertSame(0, $entry->getDuration());

        $entry->setEnd($end);
        $entry->calcDuration(1);
        static::assertSame(622, $entry->getDuration());
        $entry->calcDuration(0.5);
        static::assertSame(311, $entry->getDuration());
    }

    /**
     * @expectedExceptionMessage Duration must be greater than 0!
     */
    public function testNullDurationException(): void
    {
        $e     = null;
        $day   = '2011-11-11';
        $start = '22:22';
        $end   = '11:11';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        try {
            $entry->validateDuration();
        } catch(Exception $e) {

        }

        static::assertNotNull($e, 'An expected exception has not been raised.');
    }

    public function testToArray(): void
    {
        $entry = new Entry();

        // empty case
        $result = $entry->toArray();
        static::assertIsArray($result);
        static::assertNull($result['customer']);
        static::assertNull($result['project']);

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
        static::assertIsArray($result);
        static::assertSame(5, $result['id']);
        static::assertSame('foo', $result['description']);
        static::assertSame('TTT-51', $result['ticket']);
        static::assertNull($result['customer']);
        static::assertNull($result['project']);

        // test indirect getCustomerId call
        $entry
            ->setProject($project);
        $result = $entry->toArray();
        static::assertSame(17, $result['customer']);
        static::assertSame(21, $result['project']);

        // test project and customer
        $entry
            ->setCustomer($customer)
            ->setProject($project);
        $result = $entry->toArray();
        static::assertSame(17, $result['customer']);
        static::assertSame(21, $result['project']);

    }

}
