<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * Default implementation of ClockInterface using the system clock.
 */
class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }

    public function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today midnight');
    }
}
