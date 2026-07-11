<?php

/*
 * Copyright (c) 2025-2026 Netresearch DTT GmbH
 * SPDX-License-Identifier: AGPL-3.0-only
 */

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
    private const string FORMAT_ISO_DATETIME = 'Y-m-d\TH:i:s';

    /**
     * @param array{prompts?: int, reviews?: int, interventions?: int}|null $touchpoints
     */
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
        #[Assert\Length(max: 50, maxMessage: 'Ticket cannot be longer than 50 characters')]
        #[Assert\Regex(pattern: '/^[A-Z0-9\-_]*$/i', message: 'Invalid ticket format')]
        public string $extTicket = '',

        // ADR-025 agent-vs-human attribution. These are ADVISORY only: they are
        // honoured solely in the API-token (agent) channel by SaveEntryAction —
        // a session request forces source=human/estimated=false and ignores them
        // (a person cannot self-mark work as agent to escape attendance/ArbZG).
        // There is deliberately NO responsibleUserId/loggedBy field: the
        // responsible user is derived server-side from the token owner (a
        // client-supplied responsible id would be an IDOR).
        #[Assert\Choice(choices: ['human', 'agent'], message: 'Invalid source')]
        public ?string $source = null,
        public ?bool $estimated = null,
        public ?array $touchpoints = null,
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

        // ISO 8601 datetime first (e.g. 2026-01-14T00:00:00), then standard ISO date
        return $this->parseDateTime($this->date, [self::FORMAT_ISO_DATETIME, 'Y-m-d']);
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

        // ISO 8601 datetime first (e.g. 2026-01-14T08:00:00), then time-only formats
        return $this->parseDateTime($this->start, [self::FORMAT_ISO_DATETIME, 'H:i:s', 'H:i']);
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

        // ISO 8601 datetime first (e.g. 2026-01-14T16:00:00), then time-only formats
        return $this->parseDateTime($this->end, [self::FORMAT_ISO_DATETIME, 'H:i:s', 'H:i']);
    }

    /**
     * Parses a date/time string by trying the given formats in order,
     * falling back to generic DateTime parsing.
     *
     * @param list<string> $formats
     */
    private function parseDateTime(string $value, array $formats): ?DateTimeInterface
    {
        foreach ($formats as $format) {
            $parsed = DateTime::createFromFormat($format, $value);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        // Fallback to generic DateTime parsing
        try {
            return new DateTime($value);
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
