<?php

declare(strict_types=1);

namespace Tests\Service\Ldap;

use App\Service\Ldap\LdapClientService;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use stdClass;

/**
 * Unit tests for LdapClientService.
 *
 * @internal
 */
#[CoversClass(LdapClientService::class)]
final class LdapClientServiceTest extends TestCase
{
    // ==================== Constructor tests ====================

    public function testConstructorWithDefaults(): void
    {
        $service = new LdapClientService();
        $reflection = new ReflectionClass($service);

        $loggerProp = $reflection->getProperty('logger');
        self::assertInstanceOf(NullLogger::class, $loggerProp->getValue($service));

        $projectDirProp = $reflection->getProperty('projectDir');
        self::assertSame('', $projectDirProp->getValue($service));
    }

    public function testConstructorWithLogger(): void
    {
        $logger = new NullLogger();
        $service = new LdapClientService($logger, '/custom/path');

        $reflection = new ReflectionClass($service);
        $loggerProp = $reflection->getProperty('logger');
        self::assertSame($logger, $loggerProp->getValue($service));

        $projectDirProp = $reflection->getProperty('projectDir');
        self::assertSame('/custom/path', $projectDirProp->getValue($service));
    }

    // ==================== setHost tests ====================

    public function testSetHostWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setHost('ldap.example.com');

        self::assertSame($service, $result, 'setHost should return self for chaining');

        $reflection = new ReflectionClass($service);
        $hostProp = $reflection->getProperty('_host');
        self::assertSame('ldap.example.com', $hostProp->getValue($service));
    }

    public function testSetHostWithInteger(): void
    {
        $service = new LdapClientService();
        $service->setHost(123);

        $reflection = new ReflectionClass($service);
        $hostProp = $reflection->getProperty('_host');
        self::assertSame('123', $hostProp->getValue($service));
    }

    public function testSetHostWithNull(): void
    {
        $service = new LdapClientService();
        $service->setHost(null);

        $reflection = new ReflectionClass($service);
        $hostProp = $reflection->getProperty('_host');
        self::assertSame('', $hostProp->getValue($service));
    }

    // ==================== setPort tests ====================

    public function testSetPortWithInteger(): void
    {
        $service = new LdapClientService();
        $result = $service->setPort(636);

        self::assertSame($service, $result, 'setPort should return self for chaining');

        $reflection = new ReflectionClass($service);
        $portProp = $reflection->getProperty('_port');
        self::assertSame(636, $portProp->getValue($service));
    }

    public function testSetPortWithString(): void
    {
        $service = new LdapClientService();
        $service->setPort('389');

        $reflection = new ReflectionClass($service);
        $portProp = $reflection->getProperty('_port');
        self::assertSame(389, $portProp->getValue($service));
    }

    public function testSetPortWithNull(): void
    {
        $service = new LdapClientService();
        $service->setPort(null);

        $reflection = new ReflectionClass($service);
        $portProp = $reflection->getProperty('_port');
        self::assertSame(0, $portProp->getValue($service));
    }

    // ==================== setReadUser tests ====================

    public function testSetReadUserWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setReadUser('cn=reader,dc=example');

        self::assertSame($service, $result, 'setReadUser should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_readUser');
        self::assertSame('cn=reader,dc=example', $prop->getValue($service));
    }

    // ==================== setReadPass tests ====================

    public function testSetReadPassWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setReadPass('secretpass123');

        self::assertSame($service, $result, 'setReadPass should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_readPass');
        self::assertSame('secretpass123', $prop->getValue($service));
    }

    // ==================== setBaseDn tests ====================

    public function testSetBaseDnWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setBaseDn('dc=example,dc=com');

        self::assertSame($service, $result, 'setBaseDn should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_baseDn');
        self::assertSame('dc=example,dc=com', $prop->getValue($service));
    }

    // ==================== setUseSSL tests ====================

    public function testSetUseSSLWithTrue(): void
    {
        $service = new LdapClientService();
        $result = $service->setUseSSL(true);

        self::assertSame($service, $result, 'setUseSSL should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_useSSL');
        self::assertTrue($prop->getValue($service));
    }

    public function testSetUseSSLWithFalse(): void
    {
        $service = new LdapClientService();
        $service->setUseSSL(false);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_useSSL');
        self::assertFalse($prop->getValue($service));
    }

    public function testSetUseSSLWithTruthyValue(): void
    {
        $service = new LdapClientService();
        $service->setUseSSL(1);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_useSSL');
        self::assertTrue($prop->getValue($service));
    }

    public function testSetUseSSLWithFalsyValue(): void
    {
        $service = new LdapClientService();
        $service->setUseSSL(0);

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_useSSL');
        self::assertFalse($prop->getValue($service));
    }

    // ==================== setUserNameField tests ====================

    public function testSetUserNameFieldWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setUserNameField('uid');

        self::assertSame($service, $result, 'setUserNameField should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userNameField');
        self::assertSame('uid', $prop->getValue($service));
    }

    // ==================== setUserName tests ====================

    public function testSetUserNameWithSimpleName(): void
    {
        $service = new LdapClientService();
        $result = $service->setUserName('john.doe');

        self::assertSame($service, $result, 'setUserName should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('john.doe', $prop->getValue($service));
    }

    public function testSetUserNameNormalizesUppercase(): void
    {
        $service = new LdapClientService();
        $service->setUserName('JOHN.DOE');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('john.doe', $prop->getValue($service));
    }

    public function testSetUserNameReplacesSpacesWithDots(): void
    {
        $service = new LdapClientService();
        $service->setUserName('John Doe');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('john.doe', $prop->getValue($service));
    }

    public function testSetUserNameReplacesGermanUmlauts(): void
    {
        $service = new LdapClientService();
        $service->setUserName('Jürgen Müller');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('juergen.mueller', $prop->getValue($service));
    }

    public function testSetUserNameReplacesGermanScharfesS(): void
    {
        $service = new LdapClientService();
        $service->setUserName('Straße');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('strasse', $prop->getValue($service));
    }

    public function testSetUserNameReplacesAccentedE(): void
    {
        $service = new LdapClientService();
        $service->setUserName('René');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('rene', $prop->getValue($service));
    }

    public function testSetUserNameWithAllSpecialCharacters(): void
    {
        $service = new LdapClientService();
        $service->setUserName('Köhler Béatrice');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userName');
        self::assertSame('koehler.beatrice', $prop->getValue($service));
    }

    public function testSetUserNameThrowsExceptionForEmptyString(): void
    {
        $service = new LdapClientService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid user name: ''");

        $service->setUserName('');
    }

    public function testSetUserNameThrowsExceptionForZeroString(): void
    {
        $service = new LdapClientService();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Invalid user name: '0'");

        $service->setUserName('0');
    }

    // ==================== setUserPass tests ====================

    public function testSetUserPassWithString(): void
    {
        $service = new LdapClientService();
        $result = $service->setUserPass('myPassword123');

        self::assertSame($service, $result, 'setUserPass should return self for chaining');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userPass');
        self::assertSame('myPassword123', $prop->getValue($service));
    }

    public function testSetUserPassWithEmptyString(): void
    {
        $service = new LdapClientService();
        $service->setUserPass('');

        $reflection = new ReflectionClass($service);
        $prop = $reflection->getProperty('_userPass');
        self::assertSame('', $prop->getValue($service));
    }

    // ==================== getTeams tests ====================

    public function testGetTeamsReturnsEmptyArrayByDefault(): void
    {
        $service = new LdapClientService();

        self::assertSame([], $service->getTeams());
    }

    // ==================== getLdapOptions tests ====================

    public function testGetLdapOptionsReturnsCorrectStructure(): void
    {
        $service = new LdapClientService();
        $service->setHost('ldap.example.com')
            ->setPort(636)
            ->setReadUser('cn=reader')
            ->setReadPass('readerpass')
            ->setBaseDn('dc=example,dc=com')
            ->setUseSSL(true);

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('getLdapOptions');

        /** @var array<string, mixed> $options */
        $options = $method->invoke($service);

        self::assertArrayHasKey('useSsl', $options);
        self::assertArrayHasKey('host', $options);
        self::assertArrayHasKey('username', $options);
        self::assertArrayHasKey('password', $options);
        self::assertArrayHasKey('baseDn', $options);
        self::assertArrayHasKey('port', $options);

        self::assertTrue($options['useSsl']);
        self::assertSame('ldap.example.com', $options['host']);
        self::assertSame('cn=reader', $options['username']);
        self::assertSame('readerpass', $options['password']);
        self::assertSame('dc=example,dc=com', $options['baseDn']);
        self::assertSame(636, $options['port']);
    }

    // ==================== setTeamsByLdapResponse tests ====================

    public function testSetTeamsByLdapResponseWithMissingDn(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('setTeamsByLdapResponse');

        $method->invoke($service, ['cn' => ['Test User']]);

        self::assertSame([], $service->getTeams());
    }

    public function testSetTeamsByLdapResponseWithEmptyDn(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('setTeamsByLdapResponse');

        $method->invoke($service, ['distinguishedname' => ['']]);

        self::assertSame([], $service->getTeams());
    }

    public function testSetTeamsByLdapResponseWithNonExistentMappingFile(): void
    {
        $service = new LdapClientService(new NullLogger(), '/nonexistent/path');

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('setTeamsByLdapResponse');

        $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Sales,DC=example,DC=com']]);

        self::assertSame([], $service->getTeams());
    }

    public function testSetTeamsByLdapResponseWithValidMappingFile(): void
    {
        // Create temp directory and mapping file
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "sales: Sales Team\nengineering: Engineering Team\n");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Sales,DC=example,DC=com']]);

            self::assertSame(['Sales Team'], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseWithMultipleMatchingOUs(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "sales: Sales Team\nemployees: All Employees\n");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Sales,OU=Employees,DC=example,DC=com']]);

            self::assertCount(2, $service->getTeams());
            self::assertContains('Sales Team', $service->getTeams());
            self::assertContains('All Employees', $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseCaseInsensitiveMatching(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "Engineering: Dev Team\n");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            // DN uses lowercase 'engineering', mapping uses 'Engineering'
            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=engineering,DC=example,DC=com']]);

            self::assertSame(['Dev Team'], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseWithNoMatchingOUs(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "sales: Sales Team\n");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Marketing,DC=example,DC=com']]);

            self::assertSame([], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseWithEmptyMappingFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, '');

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Sales,DC=example,DC=com']]);

            self::assertSame([], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseWithInvalidYamlFile(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "invalid: yaml: content:\n  - broken");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            // Should not throw, just log error and return empty teams
            $method->invoke($service, ['distinguishedname' => ['CN=User,OU=Sales,DC=example,DC=com']]);

            self::assertSame([], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    public function testSetTeamsByLdapResponseUsesDnKeyFallback(): void
    {
        $tempDir = sys_get_temp_dir() . '/ldap_test_' . uniqid();
        mkdir($tempDir . '/config', 0o777, true);

        $mappingFile = $tempDir . '/config/ldap_ou_team_mapping.yml';
        file_put_contents($mappingFile, "sales: Sales Team\n");

        try {
            $service = new LdapClientService(new NullLogger(), $tempDir);

            $reflection = new ReflectionClass($service);
            $method = $reflection->getMethod('setTeamsByLdapResponse');

            // Use 'dn' key instead of 'distinguishedname'
            $method->invoke($service, ['dn' => ['CN=User,OU=Sales,DC=example,DC=com']]);

            self::assertSame(['Sales Team'], $service->getTeams());
        } finally {
            @unlink($mappingFile);
            @rmdir($tempDir . '/config');
            @rmdir($tempDir);
        }
    }

    // ==================== normalizeFirstEntry tests ====================

    public function testNormalizeFirstEntryWithNullThrowsException(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LDAP entry is null or not an array');

        $method->invoke($service, null);
    }

    public function testNormalizeFirstEntryWithNonArrayThrowsException(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LDAP entry is null or not an array');

        $method->invoke($service, 'not an array');
    }

    public function testNormalizeFirstEntryWithArrayValues(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $rawEntry = [
            'cn' => ['John Doe'],
            'mail' => ['john@example.com'],
        ];

        /** @var array<string, array<int, string>> $result */
        $result = $method->invoke($service, $rawEntry);

        self::assertSame(['John Doe'], $result['cn']);
        self::assertSame(['john@example.com'], $result['mail']);
    }

    public function testNormalizeFirstEntryWithStringValue(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $rawEntry = [
            'dn' => 'CN=User,DC=example',
        ];

        /** @var array<string, array<int, string>> $result */
        $result = $method->invoke($service, $rawEntry);

        self::assertSame(['CN=User,DC=example'], $result['dn']);
    }

    public function testNormalizeFirstEntrySkipsNonStringKeys(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $rawEntry = [
            'cn' => ['John'],
            0 => ['count value'],
            1 => ['another'],
        ];

        /** @var array<string, array<int, string>> $result */
        $result = $method->invoke($service, $rawEntry);

        self::assertArrayHasKey('cn', $result);
        self::assertArrayNotHasKey(0, $result);
        self::assertArrayNotHasKey(1, $result);
    }

    public function testNormalizeFirstEntryConvertsNumericValuesToStrings(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('normalizeFirstEntry');

        $rawEntry = [
            'uidNumber' => [1001, 1002],
            'gidNumber' => [500],
        ];

        /** @var array<string, array<int, string>> $result */
        $result = $method->invoke($service, $rawEntry);

        self::assertSame(['1001', '1002'], $result['uidNumber']);
        self::assertSame(['500'], $result['gidNumber']);
    }

    // ==================== toStringValue tests (via reflection) ====================

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function toStringValueProvider(): iterable
    {
        yield 'string value' => ['hello', 'hello'];
        yield 'integer value' => [42, '42'];
        yield 'float value' => [3.14, '3.14'];
        yield 'bool true' => [true, '1'];
        yield 'bool false' => [false, ''];
        yield 'null value' => [null, ''];
        yield 'array value' => [['test'], ''];
        yield 'object value' => [new stdClass(), ''];
    }

    #[DataProvider('toStringValueProvider')]
    public function testToStringValue(mixed $input, string $expected): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('toStringValue');

        $result = $method->invoke($service, $input);

        self::assertSame($expected, $result);
    }

    // ==================== toIntValue tests (via reflection) ====================

    /**
     * @return iterable<string, array{mixed, int}>
     */
    public static function toIntValueProvider(): iterable
    {
        yield 'integer value' => [42, 42];
        yield 'string numeric' => ['123', 123];
        yield 'float value' => [3.7, 3];
        yield 'bool true' => [true, 1];
        yield 'bool false' => [false, 0];
        yield 'null value' => [null, 0];
        yield 'array value' => [['test'], 0];
        yield 'object value' => [new stdClass(), 0];
        yield 'string non-numeric' => ['abc', 0];
    }

    #[DataProvider('toIntValueProvider')]
    public function testToIntValue(mixed $input, int $expected): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('toIntValue');

        $result = $method->invoke($service, $input);

        self::assertSame($expected, $result);
    }

    // ==================== toBoolValue tests (via reflection) ====================

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function toBoolValueProvider(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
        yield 'integer 1' => [1, true];
        yield 'integer 0' => [0, false];
        yield 'string truthy' => ['yes', true];
        yield 'empty string' => ['', false];
        yield 'null' => [null, false];
        yield 'array empty' => [[], false];
        yield 'array with items' => [['test'], true];
    }

    #[DataProvider('toBoolValueProvider')]
    public function testToBoolValue(mixed $input, bool $expected): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('toBoolValue');

        $result = $method->invoke($service, $input);

        self::assertSame($expected, $result);
    }

    // ==================== Chained setters test ====================

    public function testFluentInterface(): void
    {
        $service = new LdapClientService();

        $result = $service
            ->setHost('ldap.example.com')
            ->setPort(636)
            ->setReadUser('reader')
            ->setReadPass('pass')
            ->setBaseDn('dc=example')
            ->setUseSSL(true)
            ->setUserNameField('uid')
            ->setUserName('testuser')
            ->setUserPass('userpass');

        self::assertSame($service, $result);
    }

    // ==================== Default values test ====================

    public function testDefaultValues(): void
    {
        $service = new LdapClientService();
        $reflection = new ReflectionClass($service);

        $hostProp = $reflection->getProperty('_host');
        self::assertSame('192.168.1.2', $hostProp->getValue($service));

        $portProp = $reflection->getProperty('_port');
        self::assertSame(389, $portProp->getValue($service));

        $readUserProp = $reflection->getProperty('_readUser');
        self::assertSame('readuser', $readUserProp->getValue($service));

        $readPassProp = $reflection->getProperty('_readPass');
        self::assertSame('readuser', $readPassProp->getValue($service));

        $baseDnProp = $reflection->getProperty('_baseDn');
        self::assertSame('dc=netresearch,dc=nr', $baseDnProp->getValue($service));

        $userNameFieldProp = $reflection->getProperty('_userNameField');
        self::assertSame('sAMAccountName', $userNameFieldProp->getValue($service));

        $userNameProp = $reflection->getProperty('_userName');
        self::assertSame('', $userNameProp->getValue($service));

        $userPassProp = $reflection->getProperty('_userPass');
        self::assertSame('', $userPassProp->getValue($service));

        $useSslProp = $reflection->getProperty('_useSSL');
        self::assertFalse($useSslProp->getValue($service));
    }

    // ==================== verifyUsername tests ====================

    public function testVerifyUsernameThrowsExceptionWhenUsernameNotSet(): void
    {
        $service = new LdapClientService();

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('verifyUsername');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LDAP username must be set via setUserName() before authentication');

        $method->invoke($service);
    }

    // ==================== verifyPassword tests ====================

    public function testVerifyPasswordThrowsExceptionWhenPasswordNotSet(): void
    {
        $service = new LdapClientService();
        $service->setUserName('testuser');

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('verifyPassword');

        $ldapEntry = ['distinguishedname' => ['CN=Test,DC=example']];

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LDAP password must be set via setUserPass() before authentication');

        $method->invoke($service, $ldapEntry);
    }

    public function testVerifyPasswordThrowsExceptionWhenDnCannotBeDetermined(): void
    {
        $service = new LdapClientService();
        $service->setUserName('testuser');
        $service->setUserPass('testpass');

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('verifyPassword');

        // Entry with short/empty DN values
        $ldapEntry = [
            'distinguishedname' => [''],
            'dn' => [''],
            'entrydn' => [''],
        ];

        // Should construct DN and attempt LDAP bind (which will fail without server)
        $this->expectException(Exception::class);

        $method->invoke($service, $ldapEntry);
    }
}
