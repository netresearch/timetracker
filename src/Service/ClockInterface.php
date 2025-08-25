<?php

declare(strict_types=1);

namespace App\Service;

interface ClockInterface
{
    /**
     * Current point in time.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Start of the current day (midnight) in the application's timezone.
     */
    public function today(): \DateTimeImmutable;
}
