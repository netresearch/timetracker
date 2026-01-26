<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\LdapClientService;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for LdapClientService using ldap-dev container.
 *
 * These tests require the ldap-dev service to be running.
 * Run with: docker compose exec app-dev php vendor/phpunit/phpunit/phpunit tests/Service/Ldap/LdapClientServiceIntegrationTest.php
 *
 * @internal
 */
#[CoversClass(LdapClientService::class)]
#[RequiresPhpExtension('ldap')]
final class LdapClientServiceIntegrationTest extends TestCase
{
    private LdapClientService $service;

    protected function setUp(): void
    {
        // Configure service for ldap-dev container
        $this->service = new LdapClientService(new NullLogger(), '/var/www/html');
        $this->service
            ->setHost('ldap-dev')
            ->setPort(389)
            ->setBaseDn('dc=dev,dc=local')
            ->setReadUser('cn=admin,dc=dev,dc=local')
            ->setReadPass('admin123')
            ->setUserNameField('uid')
            ->setUseSSL(false);
    }

    // ==================== Successful login tests ====================

    public function testLoginWithValidCredentials(): void
    {
        $this->service
            ->setUserName('unittest')
            ->setUserPass('test123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    public function testLoginWithDeveloperUser(): void
    {
        $this->service
            ->setUserName('developer')
            ->setUserPass('dev123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    public function testLoginWithAdminUser(): void
    {
        $this->service
            ->setUserName('admin')
            ->setUserPass('admin123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    public function testLoginWithUsernameContainingDot(): void
    {
        // Test user i.myself has a dot in username
        $this->service
            ->setUserName('i.myself')
            ->setUserPass('myself123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    // ==================== Failed login tests ====================

    public function testLoginWithInvalidPasswordThrowsException(): void
    {
        $this->service
            ->setUserName('unittest')
            ->setUserPass('wrong_password');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Login data could not be validated/');

        $this->service->login();
    }

    public function testLoginWithNonExistentUserThrowsException(): void
    {
        $this->service
            ->setUserName('nonexistent')
            ->setUserPass('anypassword');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Username unknown.');

        $this->service->login();
    }

    public function testLoginWithEmptyPasswordThrowsException(): void
    {
        $this->service
            ->setUserName('unittest')
            ->setUserPass('');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LDAP password must be set via setUserPass() before authentication');

        $this->service->login();
    }

    // ==================== Connection error tests ====================

    public function testLoginWithWrongHostThrowsException(): void
    {
        $this->service
            ->setHost('nonexistent-ldap-host')
            ->setUserName('unittest')
            ->setUserPass('test123');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/No connection to LDAP/');

        $this->service->login();
    }

    public function testLoginWithWrongPortThrowsException(): void
    {
        $this->service
            ->setPort(9999) // Wrong port
            ->setUserName('unittest')
            ->setUserPass('test123');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/No connection to LDAP/');

        $this->service->login();
    }

    public function testLoginWithInvalidReadUserThrowsException(): void
    {
        $this->service
            ->setReadUser('cn=invalid,dc=dev,dc=local')
            ->setReadPass('wrongpass')
            ->setUserName('unittest')
            ->setUserPass('test123');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/No connection to LDAP/');

        $this->service->login();
    }

    // ==================== Username normalization with login ====================

    public function testLoginWithUppercaseUsernameNormalizesCorrectly(): void
    {
        // Should normalize 'UNITTEST' to 'unittest'
        $this->service
            ->setUserName('UNITTEST')
            ->setUserPass('test123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    public function testLoginWithSpaceInUsernameNormalizesCorrectly(): void
    {
        // 'I Myself' should normalize to 'i.myself'
        $this->service
            ->setUserName('I Myself')
            ->setUserPass('myself123');

        // login() returns literal true on success or throws Exception on failure
        // Since the return type is `true`, any assertion would be redundant.
        // If no exception is thrown, the login succeeded.
        $this->expectNotToPerformAssertions();
        $this->service->login();
    }

    // ==================== Teams extraction tests ====================

    public function testGetTeamsReturnsEmptyArrayWithoutTeamMappingFile(): void
    {
        // Service configured without valid projectDir for mapping file
        $serviceWithoutMapping = new LdapClientService(new NullLogger(), '/nonexistent/path');
        $serviceWithoutMapping
            ->setHost('ldap-dev')
            ->setPort(389)
            ->setBaseDn('dc=dev,dc=local')
            ->setReadUser('cn=admin,dc=dev,dc=local')
            ->setReadPass('admin123')
            ->setUserNameField('uid')
            ->setUseSSL(false)
            ->setUserName('unittest')
            ->setUserPass('test123');

        $serviceWithoutMapping->login();

        self::assertSame([], $serviceWithoutMapping->getTeams());
    }
}
