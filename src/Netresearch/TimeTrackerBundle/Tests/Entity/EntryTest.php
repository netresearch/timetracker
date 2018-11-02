<?php

namespace Netresearch\TimeTrackerBundle\Tests\Entity;

use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Netresearch\TimeTrackerBundle\Entity\Entry;
use Netresearch\TimeTrackerBundle\Entity\User;
use Netresearch\TimeTrackerBundle\Entity\Project;
use Netresearch\TimeTrackerBundle\Entity\Account;
use Netresearch\TimeTrackerBundle\Entity\Customer;
use Netresearch\TimeTrackerBundle\Entity\Activity;

class EntryTest extends TestCase
{
    public function testGetterSetter()
    {
        $entry = new Entry();

        // test account
        $this->assertEquals(null, $entry->getAccount());
        $this->assertEquals(null, $entry->getAccountId());
        $account = new Account();
        $account->setId(6);
        $entry->setAccount($account);
        $this->assertEquals($account, $entry->getAccount());
        $this->assertEquals(6, $entry->getAccountId());

        // test duration
        $entry->setDuration(95);
        $this->assertEquals(95, $entry->getDuration());

        // test ticket
        $entry->setTicket('ABCDE-12345678');
        $this->assertEquals('ABCDE-12345678', $entry->getTicket());

        // test class 
        $entry->setClass(Entry::CLASS_OVERLAP);
        $this->assertEquals(Entry::CLASS_OVERLAP, $entry->getClass());

        // test user
        $this->assertEquals(null, $entry->getUser());
        $this->assertEquals(null, $entry->getUserId());
        $user = new User();
        $user->setId(14);
        $entry->setUser($user);
        $this->assertEquals($user, $entry->getUser());
        $this->assertEquals(14, $entry->getUserId());

        // test project
        $this->assertEquals(null, $entry->getProject());
        $this->assertEquals(null, $entry->getProjectId());
        $project = new Project();
        $project->setId(33);
        $entry->setProject($project);
        $this->assertEquals($project, $entry->getProject());
        $this->assertEquals(33, $entry->getProjectId());

        // test customer
        $this->assertEquals(null, $entry->getCustomer());
        $this->assertEquals(null, $entry->getCustomerId());
        $customer = new Customer();
        $customer->setId(42);
        $entry->setCustomer($customer);
        $this->assertEquals($customer, $entry->getCustomer());
        $this->assertEquals(42, $entry->getCustomerId());

        // test customer
        $this->assertEquals(null, $entry->getActivity());
        $this->assertEquals(null, $entry->getActivityId());
        $activity = new Activity();
        $activity->setId(51);
        $entry->setActivity($activity);
        $this->assertEquals($activity, $entry->getActivity());
        $this->assertEquals(51, $entry->getActivityId());

        // test worklog
        $entry->setWorklogId(27);
        $this->assertEquals(27, $entry->getWorklogId());
    }

    public function testSetStart()
    {
        $day = '2011-11-11';
        $givenStart = '13:30';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($givenStart);
        $expected = $day . ' ' . $givenStart;
        $start = $entry->getStart()->format('Y-m-d H:i');
        $this->assertEquals($expected, $start, 'Got start ' . $start);
    }

    public function testSetEnd()
    {
        $day = '2011-11-11';
        $givenEnd = '13:30';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setEnd($givenEnd);
        $expected = $day . ' ' . $givenEnd;
        $end = $entry->getEnd()->format('Y-m-d H:i');
        $this->assertEquals($expected, $end, 'Got end ' . $end);
    }

    public function testInvertedTimes()
    {
        $day   = '2011-11-11';
        $start = '11:11';
        $end   = '22:22';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        $this->assertEquals($start, $entry->getStart()->format('H:i'), 'Start and end should not invert');

        $start = '22:22';
        $end   = '11:11';
        $entry->setStart($start);
        $entry->setEnd($end);
        $this->assertEquals($start, $entry->getStart()->format('H:i'), 'End should be greater or equal start');
        $this->assertEquals($start, $entry->getEnd()->format('H:i'), 'End should be greater or equal start');

    }

    public function testCalcDuration()
    {
        $day   = '2011-11-11';
        $start = '11:11';
        $end   = '21:33';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->calcDuration(1);
        $this->assertEquals(0, $entry->getDuration());

        $entry->setEnd($end);
        $entry->calcDuration(1);
        $this->assertEquals(622, $entry->getDuration());
        $entry->calcDuration(0.5);
        $this->assertEquals(311, $entry->getDuration());
    }

    /**
     * @expectedExceptionMessage Duration must be greater than 0!
     */
    public function testNullDurationException()
    {
        $day   = '2011-11-11';
        $start = '22:22';
        $end   = '11:11';
        $entry = new Entry();
        $entry->setDay($day);
        $entry->setStart($start);
        $entry->setEnd($end);
        try {
            $entry->validateDuration();
        } catch(\Exception $e) {

        }

        $this->assertNotNull($e, 'An expected exception has not been raised.');
    }

    public function testToArray()
    {
        $entry = new Entry();

        // empty case
        $result = $entry->toArray();
        $this->assertEquals(true, is_array($result));
        $this->assertEquals(null, $result['customer']);
        $this->assertEquals(null, $result['project']);

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
        $this->assertEquals(true, is_array($result));
        $this->assertEquals(5, $result['id']);
        $this->assertEquals('foo', $result['description']);
        $this->assertEquals('TTT-51', $result['ticket']);
        $this->assertEquals(null, $result['customer']);
        $this->assertEquals(null, $result['project']);

        // test indirect getCustomerId call
        $entry
            ->setProject($project);
        $result = $entry->toArray();
        $this->assertEquals(17, $result['customer']);
        $this->assertEquals(21, $result['project']);

        // test project and customer
        $entry
            ->setCustomer($customer)
            ->setProject($project);
        $result = $entry->toArray();
        $this->assertEquals(17, $result['customer']);
        $this->assertEquals(21, $result['project']);

    }

}
