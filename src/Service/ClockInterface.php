<?php

declare(strict_types=1);

namespace App\Service;

interface ClockInterface
{
    /**
     * Returns the current time as a DateTimeImmutable object.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Returns the current date (at midnight) as a DateTimeImmutable object.
     */
    public function today(): \DateTimeImmutable;
}
