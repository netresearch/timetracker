<?php

declare(strict_types=1);

namespace Tests\Traits;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * Authentication test functionality trait.
 *
 * Provides user authentication helpers for test cases requiring authenticated requests.
 * Uses Symfony's built-in loginUser() helper for clean test authentication.
 */
trait AuthenticationTestTrait
{
    /**
     * Authenticate user in session for testing purposes.
     *
     * This method now uses Symfony's built-in loginUser() helper exclusively,
     * providing cleaner test isolation without manual session manipulation.
     */
    protected function logInSession(string $user = 'unittest'): void
    {
        // Map usernames to IDs
        $userMap = [
            'unittest' => '1',
            'developer' => '2',
            'i.myself' => '3',
            'noContract' => '5',
        ];

        $userId = $userMap[$user] ?? '1';

        // Get the user entity from the database
        if ($this->serviceContainer === null) {
            throw new \RuntimeException('Service container not initialized');
        }
        /** @var \Doctrine\Persistence\ManagerRegistry $doctrine */
        $doctrine = $this->serviceContainer->get('doctrine');
        $userRepository = $doctrine->getRepository(\App\Entity\User::class);
        $userEntity = $userRepository->find($userId);

        if ($userEntity instanceof \App\Entity\User) {
            // Use Symfony's built-in loginUser() helper for clean test authentication
            $this->client->loginUser($userEntity, 'main');

            // Avoid kernel reboot to keep the same DB connection within a test method
            $this->client->disableReboot();
        }
    }

    /**
     * Helper method to login a user using form submission.
     */
    protected function loginAs(string $username, string $password): void
    {
        // Use the client created in setUp()
        $this->client->request(Request::METHOD_GET, '/login');

        $this->client->submitForm('Login', [
            'username' => $username,
            'password' => $password,
        ]);

        $this->assertResponseRedirects('/dashboard');
    }

    /**
     * Create a client with a default Authorization header.
     */
    protected function createAuthenticatedClient(string $username = 'test', string $password = 'password'): KernelBrowser
    {
        // Ensure the kernel is shut down before creating a new client
        static::ensureKernelShutdown();

        $kernelBrowser = static::createClient();
        $kernelBrowser->request(
            Request::METHOD_POST,
            '/login',
            ['username' => $username, 'password' => $password],
        );

        $this->assertResponseRedirects();

        return $kernelBrowser;
    }

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