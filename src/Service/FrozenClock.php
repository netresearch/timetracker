<?php

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;
use InvalidArgumentException;

use function sprintf;

/**
 * Clock implementation that returns a fixed/frozen time.
 *
 * Used for testing to make time-dependent code deterministic.
 * Configure via APP_FROZEN_TIME environment variable (format: Y-m-d H:i:s or Y-m-d).
 */
final readonly class FrozenClock implements ClockInterface
{
    private DateTimeImmutable $frozenTime;

    public function __construct(string $frozenTime)
    {
        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $frozenTime);
        if (false === $parsed) {
            // Try date-only format
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d', $frozenTime);
            if (false !== $parsed) {
                $parsed = $parsed->setTime(12, 0, 0);
            }
        }

        if (false === $parsed) {
            throw new InvalidArgumentException(sprintf('Invalid frozen time format: "%s". Expected Y-m-d H:i:s or Y-m-d', $frozenTime));
        }

        $this->frozenTime = $parsed;
    }

    public function now(): DateTimeImmutable
    {
        return $this->frozenTime;
    }

    public function today(): DateTimeImmutable
    {
        return $this->frozenTime->setTime(0, 0, 0);
    }
}
