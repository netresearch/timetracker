<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

/**
 * TicketHelper provides common methods for all ticket systems
 */
class TicketHelper
{
    /**
     *
     */
    const TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

    /**
     * @param $ticket
     */
    public static function checkFormat($ticket): bool
    {
        return (bool) preg_match(self::TICKET_REGEXP, (string) $ticket);
    }

    /**
     * @param $ticket
     */
    public static function getPrefix($ticket): ?string
    {
        if (! preg_match(self::TICKET_REGEXP, (string) $ticket, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
