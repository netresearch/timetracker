<?php

declare(strict_types=1);

namespace Tests\Traits;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Throwable;

/**
 * Authentication test functionality trait.
 * 
 * Provides user authentication, session management, and authentication helpers
 * for test cases requiring authenticated requests.
 */
trait AuthenticationTestTrait
{
    /**
     * Authenticate user in session for testing purposes.
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

        // Get the user entity from the database to create a security token
        $userRepository = $this->serviceContainer->get('doctrine')->getRepository(\App\Entity\User::class);
        $userEntity = $userRepository->find($userId);

        if ($userEntity) {
            // Primary: modern login helper for the "main" firewall
            $this->client->loginUser($userEntity, 'main');

            // Compatibility: also persist a session token like legacy tests expect
            $session = null;
            if ($this->serviceContainer->has('session')) {
                $session = $this->serviceContainer->get('session');
            } elseif ($this->serviceContainer->has('test.session')) {
                $session = $this->serviceContainer->get('test.session');
            }

            if ($session) {
                if (method_exists($session, 'isStarted') && !$session->isStarted()) {
                    $session->start();
                }

                $usernamePasswordToken = new UsernamePasswordToken(
                    $userEntity,
                    'main',
                    $userEntity->getRoles(),
                );
                $session->set('_security_main', serialize($usernamePasswordToken));
                $session->save();

                // Sync cookie jar with the session id
                $this->client->getCookieJar()->clear();
                $cookie = new Cookie($session->getName(), $session->getId());
                $this->client->getCookieJar()->set($cookie);
            }

            // Ensure token storage reflects the authenticated user in current request cycle
            if ($this->serviceContainer->has('security.token_storage')) {
                $tokenStorage = $this->serviceContainer->get('security.token_storage');
                try {
                    $postAuthenticationToken = new PostAuthenticationToken(
                        $userEntity,
                        'main',
                        $userEntity->getRoles(),
                    );
                    $tokenStorage->setToken($postAuthenticationToken);
                } catch (Throwable) {
                    $fallbackToken = new UsernamePasswordToken(
                        $userEntity,
                        'main',
                        $userEntity->getRoles(),
                    );
                    $tokenStorage->setToken($fallbackToken);
                }
            }

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