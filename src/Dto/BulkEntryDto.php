<?php

declare(strict_types=1);

namespace App\Dto;

use DateTime;
use Exception;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * DTO for bulk entry creation with date range and preset configuration.
 * Used by BulkEntryAction for creating multiple entries at once.
 */
final readonly class BulkEntryDto
{
    public function __construct(
        #[Assert\NotBlank(message: 'Preset ID is required')]
        #[Assert\Positive(message: 'Preset ID must be positive')]
        public int $preset = 0,
        public string $startdate = '',
        public string $enddate = '',
        public string $starttime = '',
        public string $endtime = '',
        public int $usecontract = 0,
        public int $skipweekend = 0,
        public int $skipholidays = 0,
    ) {
    }

    /**
     * Helper method to convert int to boolean.
     */
    public function isUseContract(): bool
    {
        return $this->usecontract > 0;
    }

    /**
     * Helper method to convert int to boolean.
     */
    public function isSkipWeekend(): bool
    {
        return $this->skipweekend > 0;
    }

    /**
     * Helper method to convert int to boolean.
     */
    public function isSkipHolidays(): bool
    {
        return $this->skipholidays > 0;
    }

    /**
     * @throws Exception
     */
    #[Assert\Callback]
    public function validateTimeRange(ExecutionContextInterface $executionContext): void
    {
        // Only validate time range if not using contract
        if (! $this->isUseContract() && ('' !== $this->starttime && '0' !== $this->starttime) && ('' !== $this->endtime && '0' !== $this->endtime)) {
            $startDateTime = DateTime::createFromFormat('H:i:s', $this->starttime);
            $endDateTime = DateTime::createFromFormat('H:i:s', $this->endtime);

            if (false !== $startDateTime && false !== $endDateTime && $startDateTime >= $endDateTime) {
                $executionContext->buildViolation('Die Aktivität muss mindestens eine Minute angedauert haben!')
                    ->atPath('endtime')
                    ->addViolation();
            }
        }
    }

    /**
     * @throws Exception
     */
    #[Assert\Callback]
    public function validateDateRange(ExecutionContextInterface $executionContext): void
    {
        if ('' !== $this->startdate && '0' !== $this->startdate && ('' !== $this->enddate && '0' !== $this->enddate)) {
            $startDate = DateTime::createFromFormat('Y-m-d', $this->startdate);
            $endDate = DateTime::createFromFormat('Y-m-d', $this->enddate);

            if (false !== $startDate && false !== $endDate && $startDate > $endDate) {
                $executionContext->buildViolation('Start date must be before or equal to end date')
                    ->atPath('enddate')
                    ->addViolation();
            }
        }
    }
}
