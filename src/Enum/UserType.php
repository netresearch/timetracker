<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * User type enumeration for role-based access control.
 */
enum UserType: string
{
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
            self::USER, self::DEV => ['ROLE_USER'],
            self::PL => ['ROLE_USER', 'ROLE_PL'],
            self::ADMIN => ['ROLE_USER', 'ROLE_ADMIN'],
        };
    }

    /**
     * Check if this user type has administrative privileges.
     */
    public function isAdmin(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Check if this user type has project lead privileges.
     */
    public function isPl(): bool
    {
        return $this === self::PL;
    }

    /**
     * Check if this user type has developer privileges.
     */
    public function isDev(): bool
    {
        return $this === self::DEV;
    }

    /**
     * Get display name for this user type.
     */
    public function getDisplayName(): string
    {
        return match ($this) {
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
}