<?php

declare(strict_types=1);

namespace App\Service\Validation;

use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Centralized validation service for input validation and sanitization.
 * Provides common validation patterns used across the application.
 */
class ValidationService
{
    public function __construct(
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Validates an email address.
     */
    public function validateEmail(string $email): ValidationResult
    {
        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(['message' => 'Email cannot be blank']),
            new Assert\Email(['message' => 'Invalid email format']),
            new Assert\Length([
                'max' => 255,
                'maxMessage' => 'Email cannot be longer than 255 characters',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates a username.
     */
    public function validateUsername(string $username): ValidationResult
    {
        $violations = $this->validator->validate($username, [
            new Assert\NotBlank(['message' => 'Username cannot be blank']),
            new Assert\Length([
                'min' => 3,
                'max' => 50,
                'minMessage' => 'Username must be at least 3 characters',
                'maxMessage' => 'Username cannot be longer than 50 characters',
            ]),
            new Assert\Regex([
                'pattern' => '/^[a-zA-Z0-9_\-\.]+$/',
                'message' => 'Username can only contain letters, numbers, underscores, hyphens, and dots',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates a password.
     */
    public function validatePassword(string $password): ValidationResult
    {
        $violations = $this->validator->validate($password, [
            new Assert\NotBlank(['message' => 'Password cannot be blank']),
            new Assert\Length([
                'min' => 8,
                'max' => 255,
                'minMessage' => 'Password must be at least 8 characters',
                'maxMessage' => 'Password cannot be longer than 255 characters',
            ]),
            new Assert\PasswordStrength([
                'minScore' => Assert\PasswordStrength::STRENGTH_MEDIUM,
                'message' => 'Password is too weak. Use a mix of letters, numbers, and symbols.',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates a date string.
     */
    public function validateDate(string $date, string $format = 'Y-m-d'): ValidationResult
    {
        $violations = $this->validator->validate($date, [
            new Assert\NotBlank(['message' => 'Date cannot be blank']),
            new Assert\Date(['message' => 'Invalid date format']),
        ]);

        if ($violations->count() === 0) {
            $dateTime = \DateTime::createFromFormat($format, $date);
            if (!$dateTime || $dateTime->format($format) !== $date) {
                $violations = $this->validator->validate(null, [
                    new Assert\NotNull(['message' => sprintf('Invalid date format. Expected: %s', $format)]),
                ]);
            }
        }

        return new ValidationResult($violations);
    }

    /**
     * Validates a time string.
     */
    public function validateTime(string $time, string $format = 'H:i'): ValidationResult
    {
        $violations = $this->validator->validate($time, [
            new Assert\NotBlank(['message' => 'Time cannot be blank']),
            new Assert\Regex([
                'pattern' => '/^\d{2}:\d{2}(:\d{2})?$/',
                'message' => 'Invalid time format',
            ]),
        ]);

        if ($violations->count() === 0) {
            $dateTime = \DateTime::createFromFormat($format, $time);
            if (!$dateTime || $dateTime->format($format) !== $time) {
                $violations = $this->validator->validate(null, [
                    new Assert\NotNull(['message' => sprintf('Invalid time format. Expected: %s', $format)]),
                ]);
            }
        }

        return new ValidationResult($violations);
    }

    /**
     * Validates a duration in minutes.
     */
    public function validateDuration(int $duration): ValidationResult
    {
        $violations = $this->validator->validate($duration, [
            new Assert\NotNull(['message' => 'Duration cannot be null']),
            new Assert\Positive(['message' => 'Duration must be positive']),
            new Assert\LessThanOrEqual([
                'value' => 1440, // 24 hours in minutes
                'message' => 'Duration cannot exceed 24 hours',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates a ticket number.
     */
    public function validateTicket(string $ticket): ValidationResult
    {
        $violations = $this->validator->validate($ticket, [
            new Assert\NotBlank(['message' => 'Ticket cannot be blank']),
            new Assert\Length([
                'max' => 50,
                'maxMessage' => 'Ticket cannot be longer than 50 characters',
            ]),
            new Assert\Regex([
                'pattern' => '/^[A-Z0-9\-_]+$/i',
                'message' => 'Invalid ticket format',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates a description/comment.
     */
    public function validateDescription(string $description, int $maxLength = 1000): ValidationResult
    {
        $violations = $this->validator->validate($description, [
            new Assert\Length([
                'max' => $maxLength,
                'maxMessage' => sprintf('Description cannot be longer than %d characters', $maxLength),
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates an entity ID.
     */
    public function validateEntityId(mixed $id): ValidationResult
    {
        $violations = $this->validator->validate($id, [
            new Assert\NotNull(['message' => 'ID cannot be null']),
            new Assert\Positive(['message' => 'ID must be a positive number']),
            new Assert\Type([
                'type' => 'integer',
                'message' => 'ID must be an integer',
            ]),
        ]);

        return new ValidationResult($violations);
    }

    /**
     * Validates pagination parameters.
     */
    public function validatePagination(int $page, int $limit): ValidationResult
    {
        $violations = $this->validator->validate([
            'page' => $page,
            'limit' => $limit,
        ], new Assert\Collection([
            'page' => [
                new Assert\Positive(['message' => 'Page must be positive']),
                new Assert\LessThanOrEqual([
                    'value' => 10000,
                    'message' => 'Page number is too large',
                ]),
            ],
            'limit' => [
                new Assert\Positive(['message' => 'Limit must be positive']),
                new Assert\LessThanOrEqual([
                    'value' => 100,
                    'message' => 'Limit cannot exceed 100 items',
                ]),
            ],
        ]));

        return new ValidationResult($violations);
    }

    /**
     * Validates a date range.
     */
    public function validateDateRange(string $startDate, string $endDate): ValidationResult
    {
        $startResult = $this->validateDate($startDate);
        if (!$startResult->isValid()) {
            return $startResult;
        }

        $endResult = $this->validateDate($endDate);
        if (!$endResult->isValid()) {
            return $endResult;
        }

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);

        if ($start > $end) {
            $violations = $this->validator->validate(null, [
                new Assert\NotNull(['message' => 'Start date must be before or equal to end date']),
            ]);
            return new ValidationResult($violations);
        }

        // Check for reasonable date range (e.g., not more than 1 year)
        $diff = $start->diff($end);
        if ($diff->y > 1) {
            $violations = $this->validator->validate(null, [
                new Assert\NotNull(['message' => 'Date range cannot exceed 1 year']),
            ]);
            return new ValidationResult($violations);
        }

        return new ValidationResult($this->validator->validate([]));
    }

    /**
     * Sanitizes input string by removing dangerous characters.
     */
    public function sanitizeString(string $input, bool $allowHtml = false): string
    {
        // Trim whitespace
        $input = trim($input);

        if (!$allowHtml) {
            // Remove HTML tags
            $input = strip_tags($input);
        }

        // Remove null bytes
        $input = str_replace("\0", '', $input);

        // Normalize line breaks
        $input = str_replace(["\r\n", "\r"], "\n", $input);

        return $input;
    }

    /**
     * Sanitizes filename to prevent directory traversal.
     */
    public function sanitizeFilename(string $filename): string
    {
        // Remove directory traversal sequences
        $filename = str_replace(['..', '/', '\\'], '', $filename);
        
        // Remove special characters
        $filename = preg_replace('/[^a-zA-Z0-9\-_\.]/', '', $filename);
        
        // Limit length
        if (strlen($filename) > 255) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $name = substr($name, 0, 250 - strlen($extension));
            $filename = $name . '.' . $extension;
        }
        
        return $filename;
    }

    /**
     * Validates an array of values against constraints.
     */
    public function validateArray(array $data, array $constraints): ValidationResult
    {
        $violations = $this->validator->validate($data, new Assert\Collection($constraints));
        
        return new ValidationResult($violations);
    }
}