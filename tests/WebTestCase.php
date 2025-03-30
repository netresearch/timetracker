<?php

declare(strict_types=1);

namespace Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;

/**
 * Base test case for web tests using Symfony's WebTestCase
 */
abstract class WebTestCase extends SymfonyWebTestCase
{
    /**
     * Helper method to login a user.
     */
    protected function loginAs(string $username, string $password): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        $client->submitForm('Login', [
            'username' => $username,
            'password' => $password,
        ]);

        $this->assertResponseRedirects('/dashboard');
    }
}
