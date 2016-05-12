<?php

namespace Netresearch\TimeTrackerBundle\Tests\Model;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Netresearch\TimeTrackerBundle\Model\EscalationException;

class MockTranslator {
    public function trans($string, $variables) {
        if (! is_array($variables)) {
            return $string;
        }

        return str_replace(array_keys($variables), array_values($variables), $string);
    }
}

class EscalationExceptionTest extends \PHPUnit_Framework_TestCase
{
    public function testEscalationException()
    {
        $exception = new EscalationException('ABC-123', 1800, 3600, new MockTranslator());
        $this->assertEquals('Escalation! You spent more time (1.00 h) than expected (0.50 h) on ticket ABC-123!', $exception->getMessage());
    }
}
