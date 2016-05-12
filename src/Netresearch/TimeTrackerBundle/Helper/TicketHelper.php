<?php

namespace Netresearch\TimeTrackerBundle\Helper;

/*
 * TicketHelper provides common methods for all ticket systems
 */
class TicketHelper
{
    const TICKET_REGEXP = '/^([A-Z]+)(::[0-9A-Z]+)?-([0-9]+)$/i';

    public static function checkFormat($ticket)
    {
        return (bool) preg_match(self::TICKET_REGEXP, $ticket);
    }

    public static function getPrefix($ticket)
    {
        if (! preg_match(self::TICKET_REGEXP, $ticket, $matches))
            return null;

        return $matches[1];
    }
}

