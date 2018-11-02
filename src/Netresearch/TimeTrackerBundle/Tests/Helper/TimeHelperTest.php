<?php

namespace Netresearch\TimeTrackerBundle\Tests\Helper;

require_once(dirname(__FILE__) . "/../../Helper/TimeHelper.php");

use Netresearch\TimeTrackerBundle\Helper\TimeHelper;
use PHPUnit\Framework\TestCase;

class TimeHelperTest extends TestCase
{
    /**
     * @dataProvider readable2MinutesDataProvider
     */
    public function testReadable2Minutes($minutes, $readable)
    {
        $this->assertEquals($minutes, TimeHelper::readable2minutes($readable));
    }

    public function readable2MinutesDataProvider()
    {
        return array(
            array(0, ''),
            array(0, '0'),
            array(0, '0m'),
            array(2, '2m'),
            array(2, '2'),
            array(90, '90m'),

            array(60, '1h'),
            array(90, '1.5h'),
            array(90, '1,5h'),
            array(120, '2h'),
            array(150, '2h 30m'),
            array(135, '2h 15'),

            array(8*60, '1d'),
            array(9*60, '1,125d'),
            array(16*60, '2d'),

            array(16 * 60 + 120, '2d 2h'),
            array(16 * 60 + 122, '2d 2h 2m'),
            array(20 * 60 + 152, '2,5d 2,5h 2m'),

            array(5 * 8 * 60, '1w'),
            array(10 * 8 * 60, '2w'),

            array(10 * 8 * 60 + 16*60, '2w 2d'),
            array(10 * 8 * 60 + 16*60 + 120, '2w 2d 2h'),
            array(10 * 8 * 60 + 16*60 + 122, '2w 2d 2h 2m')
        );
    }

    /**
     * @dataProvider minutes2ReadableDataProvider
     */
    public function testMinutes2Readable($readable, $minutes, $useWeeks= true)
    {
        $this->assertEquals($readable, TimeHelper::minutes2readable($minutes, $useWeeks));
    }

    public function minutes2ReadableDataProvider()
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
            array('1w', 5 * 8 * 60),
            array('2w', 10 * 8 * 60),

            array('2w 2d', 10 * 8 * 60 + 16*60),
            array('2w 2d 2h', 10 * 8 * 60 + 16*60 + 120),
            array('2w 2d 2h 2m', 10 * 8 * 60 + 16*60 + 122),

            array('12d', 10 * 8 * 60 + 16*60, false),
            array('12d 2h', 10 * 8 * 60 + 16*60 + 120, false),
            array('12d 2h 2m', 10 * 8 * 60 + 16*60 + 122, false)
        );
    }

    /**
     * @dataProvider formatDurationDataProvider
     */
    public function testFormatDuration($duration, $inDays, $value)
    {
        $this->assertEquals($value, TimeHelper::formatDuration($duration, $inDays));
    }

    public function formatDurationDataProvider()
    {
        return array(
            array (0, false, '00:00'),
            array (0, true, '00:00'),
            array (30, false, '00:30'),
            array (30, true, '00:30'),
            array (90, false, '01:30'),
            array (90, true, '01:30'),
            array (60 * 10, false, '10:00'),
            array (60 * 10, true, '10:00 (1.25 PT)'),
            array (60 * 8 * 42.5 + 15, false, '340:15'),
            array (60 * 8 * 42.5 + 15, true, '340:15 (42.53 PT)')
        );
    }

    /**
     * @dataProvider dataProviderTestFormatQuota
     */
    public function testFormatQuota($amount, $sum, $value)
    {
        $this->assertEquals($value, TimeHelper::formatQuota($amount, $sum));
    }

    public function dataProviderTestFormatQuota()
    {
        return array(
            array (0, 100, '0.00%'),
            array (100, 0, '0.00%'),
            array (100, 100, '100.00%'),
            array (45.67, 100, '45.67%')
        );
    }

    public function testGetMinutesByLetter()
    {
        $this->assertEquals(0, TimeHelper::getMinutesByLetter('f'));
        $this->assertEquals(1, TimeHelper::getMinutesByLetter(''));
        $this->assertEquals(1, TimeHelper::getMinutesByLetter('m'));
        $this->assertEquals(60, TimeHelper::getMinutesByLetter('h'));
        $this->assertEquals(60 * 8, TimeHelper::getMinutesByLetter('d'));
        $this->assertEquals(60 * 8 * 5, TimeHelper::getMinutesByLetter('w'));
    }
}
