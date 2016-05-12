<?php

namespace Netresearch\TimeTrackerBundle\Tests\Model;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Netresearch\TimeTrackerBundle\Model\JiraWorklog;
use Netresearch\TimeTrackerBundle\Helper\TimeHelper;


class JiraWorklogTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @dataProvider testMinutes2ReadableDataProvider
     */
    public function testFormatTime($readable, $minutes, $useWeeks = true)
    {
        $this->assertEquals($readable, JiraWorklog::formatTime($minutes * 60));
        $this->assertEquals($readable, TimeHelper::minutes2readable($minutes, $useWeeks));
    }

    public function testMinutes2ReadableDataProvider()
    {
        return array(
            array('0m', 0),
            array('2m', 2),

            array('1h', 60),
            array('1h 30m', 90),
            array('2h', 120),

            array('1d', 8 * 60),
            array('2d', 16 * 60),

            array('2d 2h', 16 * 60 + 120),
            array('2d 2h 2m', 16 * 60 + 122),

            array('12d', 10 * 8 * 60 + 16*60, false),
            array('12d 2h', 10 * 8 * 60 + 16*60 + 120, false),
            array('12d 2h 2m', 10 * 8 * 60 + 16*60 + 122, false)
        );
    }

    public function testGetterSetter()
    {
        $worklog = new JiraWorklog();

        // test id
        $worklog->setId(4);
        $this->assertEquals(4, $worklog->getId());

        // test author
        $worklog->setAuthor('Jimmy Doug');
        $this->assertEquals('Jimmy Doug', $worklog->getAuthor());

        // test author
        $worklog->setComment('(comment)');
        $this->assertEquals('(comment)', $worklog->getComment());

        // test timeSpentInSeconds
        $worklog->setTimeSpentInSeconds(1800);
        $this->assertEquals(1800, $worklog->getTimeSpentInSeconds());

        // test timeSpent
        $worklog->setTimeSpent($worklog->formatTime($worklog->getTimeSpentInSeconds()));
        $this->assertEquals('30m', $worklog->getTimeSpent());

        $date = date("c");
        $worklog->setCreated($date);
        $this->assertEquals($date, $worklog->getCreated());
        $worklog->setUpdated($date);
        $this->assertEquals($date, $worklog->getUpdated());

        // test update author
        $worklog->setUpdateAuthor('Jimmy Doug II');
        $this->assertEquals('Jimmy Doug II', $worklog->getUpdateAuthor());

        // test group level
        $worklog->setGroupLevel(26200);
        $this->assertEquals(26200, $worklog->getGroupLevel());

        // test role level id
        $worklog->setRoleLevelId(18400);
        $this->assertEquals(18400, $worklog->getRoleLevelId());

        // test start date
        $worklog->setStartDate(new \DateTime());
        $this->assertEquals(date('Y-m-d'), $worklog->getStartDate()->format('Y-m-d'));
    }
}
