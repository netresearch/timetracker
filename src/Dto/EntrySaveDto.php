<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Entry;
use DateTime;
use DateTimeInterface;
use Exception;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * DTO for saving/updating time tracking entries.
 * Uses Symfony ObjectMapper for automatic mapping and validation.
 */
#[Map(target: Entry::class)]
final readonly class EntrySaveDto
{
    public function __construct(
        public ?int $id = null,
        #[Assert\NotBlank(message: 'Date is required')]
        public string $date = '',
        #[Assert\NotBlank(message: 'Start time is required')]
        public string $start = '00:00:00',
        #[Assert\NotBlank(message: 'End time is required')]
        public string $end = '00:00:00',
        #[Assert\Length(max: 50, maxMessage: 'Ticket cannot be longer than 50 characters')]
        #[Assert\Regex(pattern: '/^[A-Z0-9\-_]*$/i', message: 'Invalid ticket format')]
        public string $ticket = '',
        #[Assert\Length(max: 1000, maxMessage: 'Description cannot be longer than 1000 characters')]
        public string $description = '',

        // Support both naming conventions: project_id and project
        #[Assert\Positive(message: 'Project ID must be positive')]
        public ?int $project_id = null,
        #[Assert\Positive(message: 'Customer ID must be positive')]
        public ?int $customer_id = null,
        #[Assert\Positive(message: 'Activity ID must be positive')]
        public ?int $activity_id = null,

        // Legacy field names without _id suffix
        #[Assert\Positive(message: 'Project ID must be positive')]
        public ?int $project = null,
        #[Assert\Positive(message: 'Customer ID must be positive')]
        public ?int $customer = null,
        #[Assert\Positive(message: 'Activity ID must be positive')]
        public ?int $activity = null,
        public string $extTicket = '',
    ) {
    }

    /**
     * Get customer ID with legacy field support.
     */
    public function getCustomerId(): ?int
    {
        return $this->customer_id ?? $this->customer;
    }

    /**
     * Get project ID with legacy field support.
     */
    public function getProjectId(): ?int
    {
        return $this->project_id ?? $this->project;
    }

    /**
     * Get activity ID with legacy field support.
     */
    public function getActivityId(): ?int
    {
        return $this->activity_id ?? $this->activity;
    }

    /**
     * Convert date string to DateTime object.
     * Supports ISO 8601 formats: datetime (Y-m-d\TH:i:s) and date-only (Y-m-d).
     */
    public function getDateAsDateTime(): ?DateTimeInterface
    {
        if ('' === $this->date || '0' === $this->date) {
            return null;
        }

        // Try ISO 8601 format first (from ExtJS: 2026-01-14T00:00:00)
        $date = DateTime::createFromFormat('Y-m-d\TH:i:s', $this->date);
        if (false !== $date) {
            return $date;
        }

        // Try Y-m-d format (standard ISO date)
        $date = DateTime::createFromFormat('Y-m-d', $this->date);
        if (false !== $date) {
            return $date;
        }

        // Fallback to generic DateTime parsing
        try {
            return new DateTime($this->date);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Convert start time string to DateTime object.
     * Supports ISO 8601 datetime (Y-m-d\TH:i:s), and time-only formats (H:i:s, H:i).
     */
    public function getStartAsDateTime(): ?DateTimeInterface
    {
        if ('' === $this->start || '0' === $this->start) {
            return null;
        }

        // Try ISO 8601 format first (from ExtJS: 2026-01-14T08:00:00)
        $time = DateTime::createFromFormat('Y-m-d\TH:i:s', $this->start);
        if (false !== $time) {
            return $time;
        }

        // Handle H:i:s format
        $time = DateTime::createFromFormat('H:i:s', $this->start);
        if (false !== $time) {
            return $time;
        }

        // Handle H:i format
        $time = DateTime::createFromFormat('H:i', $this->start);
        if (false !== $time) {
            return $time;
        }

        // Fallback to generic DateTime parsing
        try {
            return new DateTime($this->start);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Convert end time string to DateTime object.
     * Supports ISO 8601 datetime (Y-m-d\TH:i:s), and time-only formats (H:i:s, H:i).
     */
    public function getEndAsDateTime(): ?DateTimeInterface
    {
        if ('' === $this->end || '0' === $this->end) {
            return null;
        }

        // Try ISO 8601 format first (from ExtJS: 2026-01-14T16:00:00)
        $time = DateTime::createFromFormat('Y-m-d\TH:i:s', $this->end);
        if (false !== $time) {
            return $time;
        }

        // Handle H:i:s format
        $time = DateTime::createFromFormat('H:i:s', $this->end);
        if (false !== $time) {
            return $time;
        }

        // Handle H:i format
        $time = DateTime::createFromFormat('H:i', $this->end);
        if (false !== $time) {
            return $time;
        }

        // Fallback to generic DateTime parsing
        try {
            return new DateTime($this->end);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * Validate that the date format is valid.
     */
    #[Assert\Callback]
    public function validateDateFormat(ExecutionContextInterface $executionContext): void
    {
        if ('' === $this->date || '0' === $this->date) {
            return; // NotBlank constraint handles empty dates
        }

        $dateTime = $this->getDateAsDateTime();
        if (!$dateTime instanceof DateTimeInterface) {
            $executionContext->buildViolation('Invalid date format')
                ->atPath('date')
                ->addViolation();
        }
    }

    /**
     * Validate that start time is before end time.
     *
     * @throws Exception
     */
    #[Assert\Callback]
    public function validateTimeRange(ExecutionContextInterface $executionContext): void
    {
        $start = $this->getStartAsDateTime();
        $end = $this->getEndAsDateTime();

        if ($start instanceof DateTimeInterface && $end instanceof DateTimeInterface && $start >= $end) {
            $executionContext->buildViolation('Start time must be before end time')
                ->atPath('end')
                ->addViolation();
        }
    }
}
