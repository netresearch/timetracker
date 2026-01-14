<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Ticket system type enumeration.
 */
enum TicketSystemType: string
{
    case UNKNOWN = '';
    case JIRA = 'JIRA';
    case OTRS = 'OTRS';
    case FRESHDESK = 'FRESHDESK';

    /**
     * Get display name for this ticket system type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Unknown/Not Configured',
            self::JIRA => 'Atlassian Jira',
            self::OTRS => 'OTRS (Open Ticket Request System)',
            self::FRESHDESK => 'Freshdesk',
        };
    }

    /**
     * Get default ticket URL pattern for this system type.
     */
    public function getDefaultUrlPattern(): string
    {
        return match ($this) {
            self::UNKNOWN => '',
            self::JIRA => 'https://jira.company.com/browse/%s',
            self::OTRS => 'https://otrs.company.com/otrs/index.pl?Action=AgentTicketZoom;TicketNumber=%s',
            self::FRESHDESK => 'https://company.freshdesk.com/a/tickets/%s',
        };
    }

    /**
     * Get API endpoint pattern for this system type.
     */
    public function getApiEndpointPattern(): string
    {
        return match ($this) {
            self::UNKNOWN => '',
            self::JIRA => '/rest/api/2/issue/%s',
            self::OTRS => '/otrs/nph-genericinterface.pl/Webservice/GenericTicketConnector/Ticket/%s',
            self::FRESHDESK => '/api/v2/tickets/%s',
        };
    }

    /**
     * Check if this system supports OAuth authentication.
     */
    public function supportsOAuth(): bool
    {
        return match ($this) {
            self::UNKNOWN => false,
            self::JIRA => true,
            self::OTRS => false,
            self::FRESHDESK => false,
        };
    }

    /**
     * Check if this system supports time tracking.
     */
    public function supportsTimeTracking(): bool
    {
        return match ($this) {
            self::UNKNOWN => false,
            self::JIRA => true,
            self::OTRS => false,
            self::FRESHDESK => false,
        };
    }

    /**
     * Get required authentication fields for this system.
     *
     * @return string[]
     */
    public function getRequiredAuthFields(): array
    {
        return match ($this) {
            self::UNKNOWN => [],
            self::JIRA => ['username', 'api_token'],
            self::OTRS => ['username', 'password'],
            self::FRESHDESK => ['api_key'],
        };
    }

    /**
     * Get ticket number validation pattern for this system.
     */
    public function getTicketPattern(): string
    {
        return match ($this) {
            self::UNKNOWN => '/^.*$/',
            self::JIRA => '/^([A-Z]+)-(\d+)$/',
            self::OTRS => '/^(\d{4,})$/',
            self::FRESHDESK => '/^(\d+)$/',
        };
    }

    /**
     * Get all available ticket system types.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Get systems that support time tracking.
     *
     * @return self[]
     */
    public static function withTimeTracking(): array
    {
        return array_filter(self::cases(), static fn (self $type): bool => $type->supportsTimeTracking());
    }

    /**
     * Check if this is a valid configured system.
     */
    public function isConfigured(): bool
    {
        return self::UNKNOWN !== $this;
    }
}
