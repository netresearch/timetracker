<?php

declare(strict_types=1);

namespace App\Service\Validation;

/**
 * Exception thrown when validation fails.
 */
class ValidationException extends \Exception
{
    public function __construct(
        private readonly ValidationResult $validationResult,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if (empty($message)) {
            $message = 'Validation failed: ' . $validationResult->getErrorsAsString();
        }
        
        parent::__construct($message, $code, $previous);
    }

    /**
     * Gets the validation result.
     */
    public function getValidationResult(): ValidationResult
    {
        return $this->validationResult;
    }

    /**
     * Gets validation errors.
     */
    public function getErrors(): array
    {
        return $this->validationResult->getErrors();
    }

    /**
     * Gets validation errors by field.
     */
    public function getErrorsByField(): array
    {
        return $this->validationResult->getErrorsByField();
    }
}