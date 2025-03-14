<?php declare(strict_types=1);
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH.
 */

namespace App\Helper;

/**
 * TicketHelper provides common methods for all ticket systems.
 */
class TicketHelper
{
    final public const TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

    public static function checkFormat(string $ticket): bool
    {
        return (bool) preg_match(self::TICKET_REGEXP, $ticket);
    }

    public static function getPrefix(string $ticket): ?string
    {
        if (!preg_match(self::TICKET_REGEXP, $ticket, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
