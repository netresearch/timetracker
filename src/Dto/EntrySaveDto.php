<?php

declare(strict_types=1);

namespace App\Dto;

use DateTime;
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
        public int $id = 0,

        
        #[Assert\NotBlank(message: 'Date cannot be blank')]
        #[Assert\Date(message: 'Invalid date format')]
        public string $date = '',

        
        #[Assert\NotBlank(message: 'Start time cannot be blank')]
        #[Assert\Regex(pattern: '/^\d{2}:\d{2}(:\d{2})?$/', message: 'Invalid time format')]
        public string $start = '00:00:00',

        
        #[Assert\NotBlank(message: 'End time cannot be blank')]
        #[Assert\Regex(pattern: '/^\d{2}:\d{2}(:\d{2})?$/', message: 'Invalid time format')]
        public string $end = '00:00:00',

        
        #[Assert\Length(max: 50, maxMessage: 'Ticket cannot be longer than 50 characters')]
        #[Assert\Regex(pattern: '/^[A-Z0-9\-_]*$/i', message: 'Invalid ticket format')]
        #[Map(transform: 'strtoupper')]
        public string $ticket = '',

        
        #[Assert\Length(max: 1000, maxMessage: 'Description cannot be longer than 1000 characters')]
        #[Map(transform: 'trim')]
        public string $description = '',

        
        #[Assert\Positive(message: 'Project ID must be positive')]
        public ?int $project_id = null,

        
        #[Assert\Positive(message: 'Customer ID must be positive')]
        public ?int $customer_id = null,

        
        #[Assert\Positive(message: 'Activity ID must be positive')]
        public ?int $activity_id = null,

        
        public string $extTicket = '',
    ) {
    }

    #[Assert\Callback]
    public function validateTimeRange(ExecutionContextInterface $context): void
    {
        // Validate time range logic
        if (!empty($this->start) && !empty($this->end) && '00:00:00' !== $this->start && '00:00:00' !== $this->end) {
            $startDateTime = DateTime::createFromFormat('H:i:s', $this->start);
            $endDateTime = DateTime::createFromFormat('H:i:s', $this->end);

            if ($startDateTime && $endDateTime && $startDateTime > $endDateTime) {
                $context->buildViolation('Start time must be before end time')
                    ->atPath('start')
                    ->addViolation()
                ;
            }
        }
    }
}
