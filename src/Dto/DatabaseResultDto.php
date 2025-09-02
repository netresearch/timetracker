<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * DTO for type-safe database result transformations.
 */
final readonly class DatabaseResultDto
{
    /**
     * Transform mixed database result to typed entry data.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function transformEntryRow(array $row): array
    {
        return [
            'id' => self::safeInt($row['id'] ?? null),
            'date' => self::safeString($row['date'] ?? ''),
            'start' => self::safeString($row['start'] ?? ''),
            'end' => self::safeString($row['end'] ?? ''),
            'user' => self::safeInt($row['user'] ?? null),
            'customer' => self::safeInt($row['customer'] ?? null),
            'project' => self::safeInt($row['project'] ?? null),
            'activity' => self::safeInt($row['activity'] ?? null),
            'description' => self::safeString($row['description'] ?? ''),
            'ticket' => self::safeString($row['ticket'] ?? ''),
            'class' => self::safeInt($row['class'] ?? null),
            'duration' => self::safeInt($row['duration'] ?? null),
            'extTicket' => self::safeString($row['extTicket'] ?? ''),
            'extTicketUrl' => self::safeString($row['extTicketUrl'] ?? ''),
        ];
    }

    /**
     * Transform mixed database result to typed scope data.
     *
     * @param array<string, mixed> $row
     * @param string $scope
     * @return array{scope: string, name: string, entries: int, total: int, own: int, estimation: int}
     */
    public static function transformScopeRow(array $row, string $scope): array
    {
        return [
            'scope' => $scope,
            'name' => self::safeString($row['name'] ?? ''),
            'entries' => self::safeInt($row['entries'] ?? 0),
            'total' => self::safeInt($row['total'] ?? 0),
            'own' => self::safeInt($row['own'] ?? 0),
            'estimation' => self::safeInt($row['estimation'] ?? 0),
        ];
    }

    /**
     * Safely cast mixed value to string with fallback.
     */
    private static function safeString(mixed $value, string $default = ''): string
    {
        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }

    /**
     * Safely cast mixed value to int with fallback.
     */
    private static function safeInt(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Safely cast mixed value to DateTime string with validation.
     */
    public static function safeDateTime(mixed $value, string $default = ''): string
    {
        if (is_string($value) && !empty($value)) {
            // Validate that it's a reasonable datetime string
            if (\DateTime::createFromFormat('Y-m-d H:i:s', $value) !== false ||
                \DateTime::createFromFormat('Y-m-d', $value) !== false) {
                return $value;
            }
        }
        
        return $default;
    }
}