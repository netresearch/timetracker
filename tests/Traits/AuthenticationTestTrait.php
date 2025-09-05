<?php

declare(strict_types=1);

namespace Tests\Traits;

/**
 * Authentication helper trait for test classes.
 * 
 * Provides fluent interface methods for common user authentication patterns,
 * reducing boilerplate and improving test readability.
 */
trait AuthenticationTestTrait
{
    /**
     * Authenticate as the standard unittest user (admin level).
     * 
     * This is the most common authentication pattern (55% of usage),
     * providing full administrative access for testing.
     */
    protected function asUnittestUser(): self
    {
        $this->logInSession('unittest');
        return $this;
    }

    /**
     * Authenticate as a developer user (limited permissions).
     * 
     * Used for testing role-based access control and permission boundaries.
     * Developer users have restricted access to admin functions.
     */
    protected function asDeveloperUser(): self
    {
        $this->logInSession('developer');
        return $this;
    }

    /**
     * Authenticate as the 'i.myself' admin user.
     * 
     * Used for specialized administrative scenarios and 
     * specific user-context testing.
     */
    protected function asAdminUser(): self
    {
        $this->logInSession('i.myself');
        return $this;
    }

    /**
     * Authenticate as a user without contract access.
     * 
     * Used for testing contract-related permission boundaries.
     */
    protected function asUserWithoutContract(): self
    {
        $this->logInSession('noContract');
        return $this;
    }

    /**
     * Authenticate as a specific user by username.
     * 
     * Provides flexibility for custom authentication scenarios
     * while maintaining the fluent interface pattern.
     */
    protected function asUser(string $username): self
    {
        $this->logInSession($username);
        return $this;
    }

    /**
     * Authenticate using the default user (unittest).
     * 
     * Equivalent to calling logInSession() without parameters.
     * Provides explicit naming for clarity in test methods.
     */
    protected function asDefaultUser(): self
    {
        $this->logInSession();
        return $this;
    }
}