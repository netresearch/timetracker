<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\ModernLdapService;
use ArrayObject;
use InvalidArgumentException;
use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Ldap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function assert;
use function is_array;
use function is_string;

/**
 * Unit tests for ModernLdapService.
 *
 * @internal
 */
#[CoversClass(ModernLdapService::class)]
final class ModernLdapServiceTest extends TestCase
{
    private ParameterBagInterface&MockObject $parameterBag;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function configureDefaultParams(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.example.com'],
            ['ldap_port', 389],
            ['ldap_readuser', 'cn=reader,dc=example,dc=com'],
            ['ldap_readpass', 'readerpass'],
            ['ldap_basedn', 'dc=example,dc=com'],
            ['ldap_usernamefield', 'sAMAccountName'],
            ['ldap_usessl', false],
        ]);
    }

    private function createService(): ModernLdapService
    {
        return new ModernLdapService($this->parameterBag, $this->logger);
    }

    /**
     * Invokes a private/protected method on the service and returns string result.
     *
     * @param array<mixed> $args
     */
    private function invokeStringMethod(ModernLdapService $service, string $methodName, array $args = []): string
    {
        $reflection = new ReflectionClass(ModernLdapService::class);
        $method = $reflection->getMethod($methodName);
        $result = $method->invokeArgs($service, $args);
        assert(is_string($result));

        return $result;
    }

    /**
     * Invokes a private/protected method on the service and returns array result.
     *
     * @param array<mixed> $args
     *
     * @return array<string, mixed>
     */
    private function invokeArrayMethod(ModernLdapService $service, string $methodName, array $args = []): array
    {
        $reflection = new ReflectionClass(ModernLdapService::class);
        $method = $reflection->getMethod($methodName);
        $result = $method->invokeArgs($service, $args);
        assert(is_array($result));

        /** @var array<string, mixed> $result */
        return $result;
    }

    /**
     * Invokes a private/protected method on the service (void return).
     *
     * @param array<mixed> $args
     */
    private function invokeVoidMethod(ModernLdapService $service, string $methodName, array $args = []): void
    {
        $reflection = new ReflectionClass(ModernLdapService::class);
        $method = $reflection->getMethod($methodName);
        $method->invokeArgs($service, $args);
    }

    /**
     * Gets the config property value from the service.
     *
     * @return array<string, mixed>
     */
    private function getConfigProperty(ModernLdapService $service): array
    {
        $reflection = new ReflectionClass(ModernLdapService::class);
        $property = $reflection->getProperty('config');

        $value = $property->getValue($service);
        assert(is_array($value));

        /** @var array<string, mixed> $value */
        return $value;
    }

    // ==================== loadConfiguration tests ====================

    public function testLoadConfigurationWithDefaults(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'test.ldap.com'],
            ['ldap_port', 636],
            ['ldap_readuser', 'cn=admin'],
            ['ldap_readpass', 'secret'],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', true],
        ]);

        $service = $this->createService();
        $config = $this->getConfigProperty($service);

        self::assertSame('test.ldap.com', $config['host']);
        self::assertSame(636, $config['port']);
        self::assertSame('cn=admin', $config['readUser']);
        self::assertSame('secret', $config['readPass']);
        self::assertSame('dc=test,dc=com', $config['baseDn']);
        self::assertSame('uid', $config['userNameField']);
        self::assertTrue($config['useSsl']);
    }

    public function testLoadConfigurationWithNonScalarValues(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', ['array-value']],
            ['ldap_port', null],
            ['ldap_readuser', null],
            ['ldap_readpass', null],
            ['ldap_basedn', null],
            ['ldap_usernamefield', null],
            ['ldap_usessl', null],
        ]);

        $service = $this->createService();
        $config = $this->getConfigProperty($service);

        self::assertSame('localhost', $config['host']);
        self::assertSame(389, $config['port']);
        self::assertSame('', $config['readUser']);
        self::assertSame('', $config['readPass']);
        self::assertSame('', $config['baseDn']);
        self::assertSame('uid', $config['userNameField']);
        self::assertFalse($config['useSsl']);
    }

    public function testLoadConfigurationWithStringPort(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', '636'],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', ''],
            ['ldap_usernamefield', ''],
            ['ldap_usessl', '1'],
        ]);

        $service = $this->createService();
        $config = $this->getConfigProperty($service);

        self::assertSame(636, $config['port']);
        self::assertTrue($config['useSsl']);
    }

    // ==================== validateInput tests ====================

    public function testValidateInputThrowsForEmptyUsername(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username cannot be empty');

        $this->invokeVoidMethod($service, 'validateInput', ['', 'password']);
    }

    public function testValidateInputThrowsForEmptyPassword(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty');

        $this->invokeVoidMethod($service, 'validateInput', ['username', '']);
    }

    public function testValidateInputThrowsForTooLongUsername(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username is too long');

        $this->invokeVoidMethod($service, 'validateInput', [str_repeat('a', 256), 'password']);
    }

    public function testValidateInputAcceptsValidInput(): void
    {
        $this->expectNotToPerformAssertions();
        $this->configureDefaultParams();
        $service = $this->createService();

        // Should not throw
        $this->invokeVoidMethod($service, 'validateInput', ['validuser', 'validpassword']);
    }

    public function testValidateInputAcceptsMaxLengthUsername(): void
    {
        $this->expectNotToPerformAssertions();
        $this->configureDefaultParams();
        $service = $this->createService();

        // Should not throw - exactly 255 characters
        $this->invokeVoidMethod($service, 'validateInput', [str_repeat('a', 255), 'password']);
    }

    // ==================== sanitizeLdapInput tests ====================

    /**
     * @return array<string, array{string, string}>
     */
    public static function sanitizeLdapInputProvider(): array
    {
        return [
            'normal string' => ['john.doe', 'john.doe'],
            'backslash' => ['user\\name', 'user\5cname'],
            'asterisk' => ['user*name', 'user\2aname'],
            'open parenthesis' => ['user(name', 'user\28name'],
            'close parenthesis' => ['user)name', 'user\29name'],
            'null byte' => ["user\x00name", 'user\00name'],
            'forward slash' => ['user/name', 'user\2fname'],
            'multiple special chars' => ['u\\s*e(r)/', 'u\5cs\2ae\28r\29\2f'],
            'empty string' => ['', ''],
        ];
    }

    #[DataProvider('sanitizeLdapInputProvider')]
    public function testSanitizeLdapInput(string $input, string $expected): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'sanitizeLdapInput', [$input]);

        self::assertSame($expected, $result);
    }

    // ==================== buildSearchFilter tests ====================

    public function testBuildSearchFilterSingleCriterion(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildSearchFilter', [['cn' => 'john']]);

        self::assertSame('(cn=*john*)', $result);
    }

    public function testBuildSearchFilterMultipleCriteria(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildSearchFilter', [
            ['cn' => 'john', 'mail' => 'test'],
        ]);

        self::assertSame('(&(cn=*john*)(mail=*test*))', $result);
    }

    public function testBuildSearchFilterWithWildcard(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildSearchFilter', [['cn' => 'john*']]);

        // Wildcard is sanitized first, so * becomes \2a, then wrapped with wildcards
        // Note: This behavior means user-provided wildcards don't work as expected
        self::assertSame('(cn=*john\2a*)', $result);
    }

    public function testBuildSearchFilterSanitizesInput(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildSearchFilter', [
            ['cn' => 'john(doe)'],
        ]);

        // Parentheses should be escaped
        self::assertSame('(cn=*john\28doe\29*)', $result);
    }

    // ==================== buildUserDn tests ====================

    public function testBuildUserDn(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.example.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=example,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildUserDn', ['johndoe']);

        self::assertSame('uid=johndoe,dc=example,dc=com', $result);
    }

    public function testBuildUserDnWithSamAccountName(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.example.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'ou=users,dc=corp,dc=local'],
            ['ldap_usernamefield', 'sAMAccountName'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $result = $this->invokeStringMethod($service, 'buildUserDn', ['jsmith']);

        self::assertSame('sAMAccountName=jsmith,ou=users,dc=corp,dc=local', $result);
    }

    // ==================== buildLdapOptions tests ====================

    public function testBuildLdapOptionsWithoutSsl(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();
        $result = $this->invokeArrayMethod($service, 'buildLdapOptions', []);

        self::assertSame('ldap.test.com', $result['host']);
        self::assertSame(389, $result['port']);
        self::assertSame('dc=test,dc=com', $result['baseDn']);
        self::assertFalse($result['useSsl']);
        self::assertArrayNotHasKey('useStartTls', $result);
    }

    public function testBuildLdapOptionsWithSsl(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldaps.test.com'],
            ['ldap_port', 636],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', true],
        ]);

        $service = $this->createService();
        $result = $this->invokeArrayMethod($service, 'buildLdapOptions', []);

        self::assertSame('ldaps.test.com', $result['host']);
        self::assertSame(636, $result['port']);
        self::assertTrue($result['useSsl']);
        self::assertFalse($result['useStartTls']);
    }

    // ==================== normalizeUserData tests ====================

    public function testNormalizeUserDataWithCompleteEntry(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $entry = [
            'dn' => 'uid=jdoe,dc=test,dc=com',
            'uid' => ['jdoe'],
            'mail' => ['john.doe@test.com'],
            'givenName' => ['John'],
            'sn' => ['Doe'],
            'displayName' => ['John Doe'],
            'department' => ['Engineering'],
            'title' => ['Developer'],
        ];

        $result = $this->invokeArrayMethod($service, 'normalizeUserData', [$entry]);

        self::assertSame('uid=jdoe,dc=test,dc=com', $result['dn']);
        self::assertSame('jdoe', $result['username']);
        self::assertSame('john.doe@test.com', $result['email']);
        self::assertSame('John', $result['firstName']);
        self::assertSame('Doe', $result['lastName']);
        self::assertSame('John Doe', $result['displayName']);
        self::assertSame('Engineering', $result['department']);
        self::assertSame('Developer', $result['title']);
    }

    public function testNormalizeUserDataWithMissingFields(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $entry = [
            'dn' => 'uid=jdoe,dc=test,dc=com',
            'uid' => ['jdoe'],
        ];

        $result = $this->invokeArrayMethod($service, 'normalizeUserData', [$entry]);

        self::assertSame('uid=jdoe,dc=test,dc=com', $result['dn']);
        self::assertSame('jdoe', $result['username']);
        self::assertSame('', $result['email']);
        self::assertSame('', $result['firstName']);
        self::assertSame('', $result['lastName']);
        self::assertSame('', $result['displayName']);
        self::assertSame('', $result['department']);
        self::assertSame('', $result['title']);
    }

    public function testNormalizeUserDataWithSamAccountName(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'sAMAccountName'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $entry = [
            'dn' => 'cn=John Doe,dc=test,dc=com',
            'sAMAccountName' => ['jdoe'],
            'mail' => ['john@test.com'],
        ];

        $result = $this->invokeArrayMethod($service, 'normalizeUserData', [$entry]);

        self::assertSame('jdoe', $result['username']);
        self::assertSame('john@test.com', $result['email']);
    }

    public function testNormalizeUserDataWithEmptyArrays(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        $entry = [
            'dn' => 'uid=jdoe,dc=test,dc=com',
            'uid' => [],
            'mail' => [],
        ];

        $result = $this->invokeArrayMethod($service, 'normalizeUserData', [$entry]);

        self::assertSame('', $result['username']);
        self::assertSame('', $result['email']);
    }

    // ==================== disconnect tests ====================

    public function testDisconnectWithNoConnection(): void
    {
        $this->expectNotToPerformAssertions();
        $this->configureDefaultParams();
        $service = $this->createService();

        // Should not throw
        $this->invokeVoidMethod($service, 'disconnect', []);
    }

    // ==================== Integration-style tests with mocked Ldap ====================

    public function testAuthenticateWithInvalidCredentials(): void
    {
        $this->configureDefaultParams();

        // Create a service that will use a mock Ldap
        $service = $this->createService();

        // Use reflection to inject a mock ldap
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')
            ->willThrowException(new LdapException(null, 'Invalid credentials'));

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->authenticate('testuser', 'wrongpassword');

        self::assertFalse($result);
    }

    public function testAuthenticateLogsDebugAndWarningOnFailure(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Attempting LDAP authentication');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with('LDAP authentication failed', self::anything());

        $service = $this->createService();

        // Inject mock Ldap that fails
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')
            ->willThrowException(new LdapException(null, 'Auth failed'));

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $service->authenticate('user', 'pass');
    }

    public function testTestConnectionReturnsFalseOnFailure(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // Inject mock Ldap that fails on bind
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')
            ->willThrowException(new LdapException(null, 'Connection failed'));

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->testConnection();

        self::assertFalse($result);
    }

    public function testFindUserReturnsNullWhenNotFound(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // Create a mock search result
        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(0);

        // Inject mock Ldap
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->findUser('nonexistent');

        self::assertNull($result);
    }

    public function testGetUserGroupsReturnsEmptyWhenUserDnNotFound(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // Create a mock search result that returns no user
        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(0);

        // Inject mock Ldap
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->getUserGroups('nonexistent');

        self::assertSame([], $result);
    }

    // ==================== Additional authenticate tests ====================

    public function testAuthenticateThrowsForZeroUsername(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Username cannot be empty');

        $service->authenticate('0', 'password');
    }

    public function testAuthenticateThrowsForZeroPassword(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Password cannot be empty');

        $service->authenticate('validuser', '0');
    }

    public function testAuthenticateSuccessful(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('debug')
            ->with('Attempting LDAP authentication');

        $this->logger->expects(self::once())
            ->method('info')
            ->with('LDAP authentication successful');

        $service = $this->createService();

        // Inject mock Ldap that succeeds
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->expects(self::once())
            ->method('bind')
            ->with('sAMAccountName=testuser,dc=example,dc=com', 'correctpassword');
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->authenticate('testuser', 'correctpassword');

        self::assertTrue($result);
    }

    // ==================== Additional findUser tests ====================

    public function testFindUserThrowsOnSearchError(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // Inject mock Ldap that throws on search
        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')
            ->willThrowException(new LdapException(null, 'Search failed'));
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $this->expectException(LdapException::class);
        $service->findUser('someuser');
    }

    public function testFindUserReturnsNormalizedData(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::never())
            ->method('info')
            ->with('User not found in LDAP');

        $service = $this->createService();

        // Create mock result that returns one user
        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(1);
        $mockResult->method('current')->willReturn([
            'dn' => 'sAMAccountName=jdoe,dc=example,dc=com',
            'sAMAccountName' => ['jdoe'],
            'mail' => ['jdoe@example.com'],
            'givenName' => ['John'],
            'sn' => ['Doe'],
        ]);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->findUser('jdoe');

        self::assertIsArray($result);
        self::assertSame('sAMAccountName=jdoe,dc=example,dc=com', $result['dn']);
        self::assertSame('jdoe', $result['username']);
        self::assertSame('jdoe@example.com', $result['email']);
        self::assertSame('John', $result['firstName']);
        self::assertSame('Doe', $result['lastName']);
    }

    // ==================== Additional searchUsers tests ====================

    public function testSearchUsersReturnsResults(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('info')
            ->with('LDAP search completed');

        $service = $this->createService();

        // Create an ArrayObject to simulate the search results (implements IteratorAggregate)
        $userEntries = [
            [
                'dn' => 'sAMAccountName=user1,dc=example,dc=com',
                'sAMAccountName' => ['user1'],
                'mail' => ['user1@example.com'],
            ],
            [
                'dn' => 'sAMAccountName=user2,dc=example,dc=com',
                'sAMAccountName' => ['user2'],
                'mail' => ['user2@example.com'],
            ],
        ];

        // Use ArrayObject which implements IteratorAggregate
        $mockResult = new ArrayObject($userEntries);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->searchUsers(['cn' => 'user']);

        self::assertCount(2, $result);
        self::assertSame('user1', $result[0]['username']);
        self::assertSame('user2', $result[1]['username']);
    }

    public function testSearchUsersThrowsOnError(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('error')
            ->with('LDAP search failed', self::anything());

        $service = $this->createService();

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')
            ->willThrowException(new LdapException(null, 'Search error'));
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $this->expectException(LdapException::class);
        $service->searchUsers(['cn' => 'test']);
    }

    // ==================== Additional testConnection tests ====================

    public function testTestConnectionSuccessful(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('info')
            ->with('LDAP connection test successful');

        $service = $this->createService();

        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(1);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->testConnection();

        self::assertTrue($result);
    }

    public function testTestConnectionReturnsFalseOnEmptyResult(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(0);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->testConnection();

        self::assertFalse($result);
    }

    // ==================== Additional getUserGroups tests ====================

    public function testGetUserGroupsThrowsOnError(): void
    {
        $this->configureDefaultParams();

        $this->logger->expects(self::once())
            ->method('error')
            ->with('Failed to get user groups', self::anything());

        $service = $this->createService();

        // First call to findUser returns user with DN
        $findUserResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $findUserResult->method('count')->willReturn(1);
        $findUserResult->method('current')->willReturn([
            'dn' => 'cn=test,dc=example,dc=com',
            'sAMAccountName' => ['testuser'],
        ]);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')
            ->willReturnOnConsecutiveCalls(
                $findUserResult,
                $this->throwException(new LdapException(null, 'Groups search failed')),
            );
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $this->expectException(LdapException::class);
        $service->getUserGroups('testuser');
    }

    public function testGetUserGroupsReturnsGroupsForUser(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // First call to findUser returns user with DN
        $findUserResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $findUserResult->method('count')->willReturn(1);
        $findUserResult->method('current')->willReturn([
            'dn' => 'cn=testuser,dc=example,dc=com',
            'sAMAccountName' => ['testuser'],
        ]);

        // Second call returns groups using ArrayObject
        $groupEntries = [
            [
                'cn' => ['Developers'],
                'description' => ['Development team'],
            ],
            [
                'cn' => ['Admins'],
                'description' => ['Admin team'],
            ],
        ];

        $groupsResult = new ArrayObject($groupEntries);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')
            ->willReturnOnConsecutiveCalls($findUserResult, $groupsResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        $result = $service->getUserGroups('testuser');

        self::assertCount(2, $result);
        self::assertSame('Developers', $result[0]['name']);
        self::assertSame('Development team', $result[0]['description']);
        self::assertSame('Admins', $result[1]['name']);
        self::assertSame('Admin team', $result[1]['description']);
    }

    // ==================== Edge case tests ====================

    public function testGetUserDnReturnsNullForNonArrayResult(): void
    {
        $this->configureDefaultParams();
        $service = $this->createService();

        // Create a mock search result that returns no user
        $mockResult = $this->createMock(\Laminas\Ldap\Collection\DefaultIterator::class);
        $mockResult->method('count')->willReturn(0);

        $mockLdap = $this->createMock(Ldap::class);
        $mockLdap->method('bind')->willReturn($mockLdap);
        $mockLdap->method('search')->willReturn($mockResult);
        $mockLdap->method('disconnect');

        $reflection = new ReflectionClass($service);
        $ldapProperty = $reflection->getProperty('ldap');
        $ldapProperty->setValue($service, $mockLdap);

        // getUserDn is private, so we test via getUserGroups
        $result = $service->getUserGroups('nonexistent');
        self::assertSame([], $result);
    }

    public function testNormalizeUserDataWithNonArrayFieldValue(): void
    {
        $this->parameterBag->method('get')->willReturnMap([
            ['ldap_host', 'ldap.test.com'],
            ['ldap_port', 389],
            ['ldap_readuser', ''],
            ['ldap_readpass', ''],
            ['ldap_basedn', 'dc=test,dc=com'],
            ['ldap_usernamefield', 'uid'],
            ['ldap_usessl', false],
        ]);

        $service = $this->createService();

        // Test with non-array values for uid field
        $entry = [
            'dn' => 'uid=jdoe,dc=test,dc=com',
            'uid' => 'not-an-array', // non-array value
            'mail' => null, // null value
        ];

        $result = $this->invokeArrayMethod($service, 'normalizeUserData', [$entry]);

        // Non-array uid should result in empty username
        self::assertSame('', $result['username']);
        self::assertSame('', $result['email']);
    }
}
