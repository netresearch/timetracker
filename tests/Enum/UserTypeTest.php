<?php

declare(strict_types=1);

namespace Tests\Enum;

use App\Enum\UserType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UserType enum.
 *
 * @internal
 */
#[CoversClass(UserType::class)]
final class UserTypeTest extends TestCase
{
    // ==================== Case value tests ====================

    public function testUnknownHasEmptyValue(): void
    {
        self::assertSame('', UserType::UNKNOWN->value);
    }

    public function testUserHasCorrectValue(): void
    {
        self::assertSame('USER', UserType::USER->value);
    }

    public function testDevHasCorrectValue(): void
    {
        self::assertSame('DEV', UserType::DEV->value);
    }

    public function testPlHasCorrectValue(): void
    {
        self::assertSame('PL', UserType::PL->value);
    }

    public function testAdminHasCorrectValue(): void
    {
        self::assertSame('ADMIN', UserType::ADMIN->value);
    }

    // ==================== getRoles tests ====================

    public function testGetRolesForUnknown(): void
    {
        $roles = UserType::UNKNOWN->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertCount(1, $roles);
    }

    public function testGetRolesForUser(): void
    {
        $roles = UserType::USER->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertCount(1, $roles);
    }

    public function testGetRolesForDev(): void
    {
        $roles = UserType::DEV->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertCount(1, $roles);
    }

    public function testGetRolesForPl(): void
    {
        $roles = UserType::PL->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_PL', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertCount(3, $roles);
    }

    public function testGetRolesForAdmin(): void
    {
        $roles = UserType::ADMIN->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertCount(2, $roles);
    }

    // ==================== isAdmin tests ====================

    public function testIsAdminForUnknown(): void
    {
        self::assertFalse(UserType::UNKNOWN->isAdmin());
    }

    public function testIsAdminForUser(): void
    {
        self::assertFalse(UserType::USER->isAdmin());
    }

    public function testIsAdminForDev(): void
    {
        self::assertFalse(UserType::DEV->isAdmin());
    }

    public function testIsAdminForPl(): void
    {
        self::assertTrue(UserType::PL->isAdmin());
    }

    public function testIsAdminForAdmin(): void
    {
        self::assertTrue(UserType::ADMIN->isAdmin());
    }

    // ==================== isPl tests ====================

    public function testIsPlForUnknown(): void
    {
        self::assertFalse(UserType::UNKNOWN->isPl());
    }

    public function testIsPlForUser(): void
    {
        self::assertFalse(UserType::USER->isPl());
    }

    public function testIsPlForDev(): void
    {
        self::assertFalse(UserType::DEV->isPl());
    }

    public function testIsPlForPl(): void
    {
        self::assertTrue(UserType::PL->isPl());
    }

    public function testIsPlForAdmin(): void
    {
        self::assertFalse(UserType::ADMIN->isPl());
    }

    // ==================== isDev tests ====================

    public function testIsDevForUnknown(): void
    {
        self::assertFalse(UserType::UNKNOWN->isDev());
    }

    public function testIsDevForUser(): void
    {
        self::assertFalse(UserType::USER->isDev());
    }

    public function testIsDevForDev(): void
    {
        self::assertTrue(UserType::DEV->isDev());
    }

    public function testIsDevForPl(): void
    {
        self::assertFalse(UserType::PL->isDev());
    }

    public function testIsDevForAdmin(): void
    {
        self::assertFalse(UserType::ADMIN->isDev());
    }

    // ==================== getDisplayName tests ====================

    public function testGetDisplayNameForUnknown(): void
    {
        self::assertSame('Unknown/Not Configured', UserType::UNKNOWN->getDisplayName());
    }

    public function testGetDisplayNameForUser(): void
    {
        self::assertSame('User', UserType::USER->getDisplayName());
    }

    public function testGetDisplayNameForDev(): void
    {
        self::assertSame('Developer', UserType::DEV->getDisplayName());
    }

    public function testGetDisplayNameForPl(): void
    {
        self::assertSame('Project Lead', UserType::PL->getDisplayName());
    }

    public function testGetDisplayNameForAdmin(): void
    {
        self::assertSame('Administrator', UserType::ADMIN->getDisplayName());
    }

    // ==================== all() tests ====================

    public function testAllReturnsAllCases(): void
    {
        $all = UserType::all();

        self::assertCount(5, $all);
        self::assertContains(UserType::UNKNOWN, $all);
        self::assertContains(UserType::USER, $all);
        self::assertContains(UserType::DEV, $all);
        self::assertContains(UserType::PL, $all);
        self::assertContains(UserType::ADMIN, $all);
    }

    // ==================== isConfigured tests ====================

    public function testIsConfiguredForUnknown(): void
    {
        self::assertFalse(UserType::UNKNOWN->isConfigured());
    }

    public function testIsConfiguredForUser(): void
    {
        self::assertTrue(UserType::USER->isConfigured());
    }

    public function testIsConfiguredForDev(): void
    {
        self::assertTrue(UserType::DEV->isConfigured());
    }

    public function testIsConfiguredForPl(): void
    {
        self::assertTrue(UserType::PL->isConfigured());
    }

    public function testIsConfiguredForAdmin(): void
    {
        self::assertTrue(UserType::ADMIN->isConfigured());
    }

    // ==================== Type casting tests ====================

    public function testCanCreateFromString(): void
    {
        self::assertSame(UserType::USER, UserType::from('USER'));
        self::assertSame(UserType::DEV, UserType::from('DEV'));
        self::assertSame(UserType::PL, UserType::from('PL'));
        self::assertSame(UserType::ADMIN, UserType::from('ADMIN'));
        self::assertSame(UserType::UNKNOWN, UserType::from(''));
    }

    public function testTryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(UserType::tryFrom('INVALID'));
        self::assertNull(UserType::tryFrom('user')); // lowercase
        self::assertNull(UserType::tryFrom('admin')); // lowercase
    }
}
