<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Legacy static facade kept for BC. Internally delegates to TicketService.
 */
class TicketHelper
{
    public const TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

    public static function checkFormat($ticket): bool
    {
        return (new \App\Service\Util\TicketService())->checkFormat((string) $ticket);
    }

    public static function getPrefix($ticket): ?string
    {
        return (new \App\Service\Util\TicketService())->getPrefix((string) $ticket);
    }
}
