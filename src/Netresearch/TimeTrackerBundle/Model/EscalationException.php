<?php
namespace Netresearch\TimeTrackerBundle\Model;

use \Exception;

/*
 * Worklog Escalation Exception
 */
class EscalationException extends Exception
{
    public function __construct($ticket, $escalationSeconds, $sumSeconds, $translator)
    {
        $this->message = $translator->trans(
            'Escalation! You spent more time (%spent% h) than expected (%expected% h) on ticket %ticket%!',
            array(
                '%spent%'    => number_format($sumSeconds/3600, 2),
                '%expected%' => number_format($escalationSeconds/3600, 2),
                '%ticket%'   => $ticket
            )
        );
    }
}

