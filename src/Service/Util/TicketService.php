<?php

declare(strict_types=1);

namespace App\Service\Util;

class TicketService
{
    public const string TICKET_REGEXP = '/^([A-Z]+[0-9A-Z]*)-([0-9]+)$/i';

    public function checkFormat(string $ticket): bool
    {
        return (bool) preg_match(self::TICKET_REGEXP, $ticket);
    }

    public function getPrefix(string $ticket): ?string
    {
        if (!preg_match(self::TICKET_REGEXP, $ticket, $matches)) {
            return null;
        }

        return $matches[1];
    }

    public function extractJiraId(string $ticket): string
    {
        return $this->getPrefix($ticket) ?? '';
    }
}
