<?php

declare(strict_types=1);

namespace Tests\Service; // Note: Namespace changed to Tests

use App\Service\ClockInterface;

/**
 * Test implementation of ClockInterface allowing time manipulation.
 */
class TestClock implements ClockInterface
{
    private \DateTimeImmutable $dateTimeImmutable;

    public function __construct(\DateTimeImmutable $startTime = null)
    {
        $this->dateTimeImmutable = $startTime ?? new \DateTimeImmutable('2023-10-24 12:00:00'); // Default fixed time
    }

    public function now(): \DateTimeImmutable
    {
        return $this->dateTimeImmutable;
    }

    public function today(): \DateTimeImmutable
    {
        // Return the date part at midnight
        return $this->dateTimeImmutable->setTime(0, 0, 0);
    }

    /**
     * Sets the current time for the test clock.
     */
    public function setTestNow(\DateTimeImmutable $testTime): void
    {
        $this->dateTimeImmutable = $testTime;
    }
}
