<?php

declare(strict_types=1);

namespace App\Service\Validation;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Encapsulates validation results with helper methods.
 */
class ValidationResult
{
    public function __construct(
        private readonly ConstraintViolationListInterface $violations,
    ) {
    }

    /**
     * Checks if validation passed.
     */
    public function isValid(): bool
    {
        return $this->violations->count() === 0;
    }

    /**
     * Gets all error messages.
     */
    public function getErrors(): array
    {
        $errors = [];
        
        foreach ($this->violations as $violation) {
            $errors[] = $violation->getMessage();
        }
        
        return $errors;
    }

    /**
     * Gets errors as associative array with property paths.
     */
    public function getErrorsByField(): array
    {
        $errors = [];
        
        foreach ($this->violations as $violation) {
            $propertyPath = $violation->getPropertyPath();
            $errors[$propertyPath][] = $violation->getMessage();
        }
        
        return $errors;
    }

    /**
     * Gets the first error message.
     */
    public function getFirstError(): ?string
    {
        if ($this->violations->count() === 0) {
            return null;
        }
        
        return $this->violations->get(0)->getMessage();
    }

    /**
     * Gets errors as a single string.
     */
    public function getErrorsAsString(string $separator = ', '): string
    {
        return implode($separator, $this->getErrors());
    }

    /**
     * Gets the count of violations.
     */
    public function getErrorCount(): int
    {
        return $this->violations->count();
    }

    /**
     * Gets the underlying violations.
     */
    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * Throws an exception if validation failed.
     *
     * @throws ValidationException
     */
    public function throwIfInvalid(): void
    {
        if (!$this->isValid()) {
            throw new ValidationException($this);
        }
    }
}