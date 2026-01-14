<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * User type enumeration for role-based access control.
 */
enum UserType: string
{
    case UNKNOWN = '';
    case USER = 'USER';
    case DEV = 'DEV';
    case PL = 'PL';
    case ADMIN = 'ADMIN';

    /**
     * Get Symfony roles for this user type.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        return match ($this) {
            self::UNKNOWN => ['ROLE_USER'],
            self::USER, self::DEV => ['ROLE_USER'],
            // PL has ROLE_ADMIN for v4 compatibility (PL was admin in v4)
            // TODO: Remove ROLE_ADMIN from PL when proper ADMIN users are established
            self::PL => ['ROLE_USER', 'ROLE_PL', 'ROLE_ADMIN'],
            self::ADMIN => ['ROLE_USER', 'ROLE_ADMIN'],
        };
    }

    /**
     * Check if this user type has administrative privileges.
     * Note: PL has admin rights for v4 compatibility.
     */
    public function isAdmin(): bool
    {
        return self::ADMIN === $this || self::PL === $this;
    }

    /**
     * Check if this user type has project lead privileges.
     */
    public function isPl(): bool
    {
        return self::PL === $this;
    }

    /**
     * Check if this user type has developer privileges.
     */
    public function isDev(): bool
    {
        return self::DEV === $this;
    }

    /**
     * Get display name for this user type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
            self::UNKNOWN => 'Unknown/Not Configured',
            self::USER => 'User',
            self::DEV => 'Developer',
            self::PL => 'Project Lead',
            self::ADMIN => 'Administrator',
        };
    }

    /**
     * Get all available user types.
     *
     * @return self[]
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * Check if this is a valid configured user type.
     */
    public function isConfigured(): bool
    {
        return self::UNKNOWN !== $this;
    }
}
