<?php

declare(strict_types=1);

namespace App\Dto;

use DateTime;
use DateTimeInterface;
use Symfony\Component\ObjectMapper\Attribute\Map;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * DTO for saving/updating time tracking entries.
 * Uses Symfony ObjectMapper for automatic mapping and validation.
 */
#[Map(target: \App\Entity\Entry::class)]
final readonly class EntrySaveDto
{
    public function __construct(
        public ?int $id = null,

        #[Assert\NotBlank(message: 'Date is required')]
        #[Assert\Date(message: 'Invalid date format')]
        public string $date = '',

        #[Assert\NotBlank(message: 'Start time is required')]
        #[Assert\Time(message: 'Invalid start time format')]
        public string $start = '00:00:00',

        #[Assert\NotBlank(message: 'End time is required')]
        #[Assert\Time(message: 'Invalid end time format')]
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
     * @throws \Exception
     */
    public function getDateAsDateTime(): ?DateTimeInterface
    {
        if (empty($this->date)) {
            return null;
        }

        $date = DateTime::createFromFormat('Y-m-d', $this->date);

        return $date ?: null;
    }

    /**
     * Convert start time string to DateTime object.
     * @throws \Exception
     */
    public function getStartAsDateTime(): ?DateTimeInterface
    {
        if (empty($this->start)) {
            return null;
        }

        // Handle both H:i and H:i:s formats
        $time = DateTime::createFromFormat('H:i:s', $this->start);
        if (false === $time) {
            $time = DateTime::createFromFormat('H:i', $this->start);
        }

        return $time ?: null;
    }

    /**
     * Convert end time string to DateTime object.
     * @throws \Exception
     */
    public function getEndAsDateTime(): ?DateTimeInterface
    {
        if (empty($this->end)) {
            return null;
        }

        // Handle both H:i and H:i:s formats
        $time = DateTime::createFromFormat('H:i:s', $this->end);
        if (false === $time) {
            $time = DateTime::createFromFormat('H:i', $this->end);
        }

        return $time ?: null;
    }

    /**
     * Validate that start time is before end time.
     * @throws \Exception
     */
    #[Assert\Callback]
    public function validateTimeRange(ExecutionContextInterface $context): void
    {
        $start = $this->getStartAsDateTime();
        $end = $this->getEndAsDateTime();

        if ($start && $end && $start >= $end) {
            $context->buildViolation('Start time must be before end time')
                ->atPath('end')
                ->addViolation()
            ;
        }
    }
}
