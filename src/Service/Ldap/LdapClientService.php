<?php

declare(strict_types=1);

namespace App\Service\Ldap;

use BackedEnum;
use Exception;
use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Ldap;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use UnitEnum;

use function array_slice;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function sprintf;
use function strlen;

class LdapClientService
{
    /** @var string LDAP host name or IP. */
    protected $_host = '192.168.1.2';

    /** @var int LDAP host port. */
    protected $_port = 389;

    /** @var string LDAP read user name. */
    protected $_readUser = 'readuser';

    /** @var string LDAP read user password. */
    protected $_readPass = 'readuser';

    /** @var string LDAP base DN. */
    protected $_baseDn = 'dc=netresearch,dc=nr';

    /** @var string Accountname-Field in LDAP. */
    protected $_userNameField = 'sAMAccountName';

    /** @var string LDAP user auth name - must be set before use */
    // Initialize security-sensitive properties to prevent accidental usage
    // These MUST be explicitly set via setUserName() and setUserPass() before authentication
    protected string $_userName = '';

    /** @var string LDAP user auth password - must be set before use */
    protected string $_userPass = '';

    /** @var bool Use SSL for LDAP-connection. */
    protected $_useSSL = false;

    /** @var array<string> */
    protected $teams = [];

    public function __construct(protected ?LoggerInterface $logger = null, protected string $projectDir = '')
    {
    }

    /**
     * @return (bool|int|string)[] LDAP options
     *
     * @psalm-return array{useSsl: bool, host: string, username: string, password: string, baseDn: string, port: int}
     */
    protected function getLdapOptions(): array
    {
        return [
            'useSsl' => $this->_useSSL,
            'host' => $this->_host,
            'username' => $this->_readUser,
            'password' => $this->_readPass,
            'baseDn' => $this->_baseDn,
            'port' => $this->_port,
        ];
    }

    /**
     * Verify username by searching for it in LDAP.
     *
     * @throws Exception
     * @throws LdapException
     *
     * @return array<string, array<int, string>>
     */
    protected function verifyUsername(): array
    {
        // Security check: ensure username is properly set
        if ('' === $this->_userName) {
            throw new Exception('LDAP username must be set via setUserName() before authentication');
        }

        $ldapOptions = $this->getLdapOptions();
        $ldap = new Ldap($ldapOptions);

        putenv('LDAPTLS_REQCERT=never');

        try {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('LDAP: Attempting read-only bind', [
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $this->_readUser,
                    'baseDn' => $this->_baseDn,
                ]);
            }

            $ldap->bind();
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->info('LDAP: Read-only bind successful.');
            }
        } catch (LdapException $ldapException) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('LDAP: Read-only bind failed', [
                    'error' => $ldapException->getMessage(),
                    'code' => $ldapException->getCode(),
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $this->_readUser,
                    'baseDn' => $this->_baseDn,
                ]);
            }

            throw new Exception('No connection to LDAP: ' . $this->getLdapOptions()['host'] . ': ' . $ldapException->getMessage() . '', $ldapException->getCode(), $ldapException);
        }

        $searchFilter = '(' . $this->_userNameField . '=' . ldap_escape($this->_userName) . ')';
        if ($this->logger instanceof LoggerInterface) {
            $this->logger->debug('LDAP: Searching for user', [
                'baseDn' => $this->_baseDn,
                'filter' => $searchFilter,
                'scope' => 'sub',
            ]);
        }

        $collection = $ldap->search(
            $searchFilter,
            $this->_baseDn,
            Ldap::SEARCH_SCOPE_SUB,
            ['cn', 'distinguishedName', 'dn'],
        );

        if (!is_object($collection) || (0 === $collection->count())) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->warning('LDAP: User search failed or returned no results.', [
                    'filter' => $searchFilter,
                    'baseDn' => $this->_baseDn,
                ]);
            }

            throw new Exception('Username unknown.');
        }

        if ($collection->count() > 1 && $this->logger instanceof LoggerInterface) {
            $this->logger->warning('LDAP: User search returned multiple results. Using the first one.', [
                'filter' => $searchFilter,
                'baseDn' => $this->_baseDn,
                'count' => $collection->count(),
            ]);
        }

        $ldapEntry = $this->normalizeFirstEntry($collection->getFirst());
        if ($this->logger instanceof LoggerInterface) {
            $returnedDn = $ldapEntry['distinguishedname'][0] ?? ($ldapEntry['dn'][0] ?? 'N/A');
            $this->logger->info('LDAP: User found.', [
                'dn_returned' => $returnedDn,
                'cn' => $ldapEntry['cn'][0] ?? 'N/A',
            ]);
            // Debug: log the complete LDAP entry structure
            $this->logger->debug('LDAP: Complete entry structure for debugging.', [
                'entry_keys' => array_keys($ldapEntry),
                'entry_sample' => array_map(static fn (array $val): string => implode(', ', array_slice($val, 0, 2)), $ldapEntry),
            ]);
        }

        $this->setTeamsByLdapResponse($ldapEntry);

        return $ldapEntry;
    }

    /**
     * Verify password by logging in to ldap using the user's name and password.
     *
     * @param array<string, array<int, string>> $ldapEntry
     *
     * @throws Exception
     *
     * @return true
     */
    protected function verifyPassword(array $ldapEntry): bool
    {
        // Security check: ensure password is properly set
        if ('' === $this->_userPass) {
            throw new Exception('LDAP password must be set via setUserPass() before authentication');
        }

        // Try multiple ways to extract the DN from LDAP response
        $userDn = $ldapEntry['distinguishedname'][0] ??
                  $ldapEntry['dn'][0] ??
                  $ldapEntry['entrydn'][0] ??
                  null;

        // If DN extraction failed or returned invalid data, construct it from username
        if (null === $userDn || '' === $userDn || strlen($userDn) < 10) {
            // Construct DN using the original username and our known structure
            $userDn = sprintf('uid=%s,ou=users,%s', $this->_userName, $this->_baseDn);
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('LDAP: Constructed DN from username and base DN.', [
                    'original_dn' => $ldapEntry['dn'][0] ?? 'N/A',
                    'constructed_dn' => $userDn,
                    'username' => $this->_userName,
                    'base_dn' => $this->_baseDn,
                ]);
            }
        }

        if ('' === $userDn || '0' === $userDn) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('LDAP: Could not extract or construct DN from user entry.', [
                    'entry_keys' => array_keys($ldapEntry),
                    'entry' => array_map(static fn (array $val): string => implode(', ', $val), $ldapEntry),
                ]);
            }

            throw new Exception('Could not determine user DN for authentication.');
        }

        $ldapOptions = $this->getLdapOptions();
        $ldapOptions['username'] = $userDn;
        $ldapOptions['password'] = $this->_userPass;

        $ldap = new Ldap($ldapOptions);

        try {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('LDAP: Attempting user bind.', [
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $userDn,
                ]);
            }

            $ldap->bind();
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->info('LDAP: User bind successful.', ['bindDn' => $userDn]);
            }
        } catch (LdapException $ldapException) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->error('LDAP: User bind failed.', [
                    'error' => $ldapException->getMessage(),
                    'code' => $ldapException->getCode(),
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $userDn,
                ]);
            }

            throw new Exception('Login data could not be validated: ' . $ldapException->getMessage(), $ldapException->getCode(), $ldapException);
        }

        return true;
    }

    public function setUserName(string $username): static
    {
        if ('' === $username || '0' === $username) {
            throw new Exception(sprintf("Invalid user name: '%s'", $username));
        }

        $this->_userName = str_replace(
            [' ', 'ä', 'ö', 'ü', 'ß', 'é'],
            ['.', 'ae', 'oe', 'ue', 'ss', 'e'],
            strtolower($username),
        );

        return $this;
    }

    public function setUserPass(string $password): static
    {
        $this->_userPass = $password;

        return $this;
    }

    /**
     * Normalize a mixed value to string without triggering invalid casts.
     */
    private function toStringValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return '';
    }

    /**
     * Normalize a mixed value to int without triggering invalid casts.
     */
    private function toIntValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return (int) $value;
        }

        if ($value instanceof BackedEnum) {
            return (int) $value->value;
        }

        if (is_float($value) || is_bool($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Normalize a mixed value to bool without triggering invalid casts.
     */
    private function toBoolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value instanceof BackedEnum) {
            return (bool) $value->value;
        }

        return (bool) $value;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $host
     */
    public function setHost($host): static
    {
        $this->_host = $this->toStringValue($host);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $port
     */
    public function setPort($port): static
    {
        $this->_port = $this->toIntValue($port);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $readUser
     */
    public function setReadUser($readUser): static
    {
        $this->_readUser = $this->toStringValue($readUser);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $readPass
     */
    public function setReadPass($readPass): static
    {
        $this->_readPass = $this->toStringValue($readPass);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $base_dn
     */
    public function setBaseDn($base_dn): static
    {
        $this->_baseDn = $this->toStringValue($base_dn);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $useSSL
     */
    public function setUseSSL($useSSL): static
    {
        $this->_useSSL = $this->toBoolValue($useSSL);

        return $this;
    }

    /**
     * @param string|int|float|bool|UnitEnum|null $userNameField
     */
    public function setUserNameField($userNameField): static
    {
        $this->_userNameField = $this->toStringValue($userNameField);

        return $this;
    }

    /**
     * Authenticate username and password at the LDAP server.
     *
     * @throws LdapException
     */
    public function login(): true
    {
        $result = $this->verifyPassword(
            $this->verifyUsername(),
        );
        // verifyPassword returns bool true; enforce literal true for signature
        if (!$result) {
            throw new RuntimeException('LDAP verification did not return true');
        }

        return true;
    }

    /**
     * @return array<string>
     */
    public function getTeams()
    {
        return $this->teams;
    }

    /**
     * @param array<string, array<int, string>> $ldapRespsonse
     */
    protected function setTeamsByLdapResponse(array $ldapRespsonse): void
    {
        $dn = $ldapRespsonse['distinguishedname'][0] ?? ($ldapRespsonse['dn'][0] ?? null);
        if (null === $dn || '' === $dn) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->warning('LDAP: Cannot map teams, DN is missing from LDAP response.', ['response' => $ldapRespsonse]);
            }

            return;
        }

        $mappingFile = rtrim($this->projectDir, '/') . '/config/ldap_ou_team_mapping.yml';

        $this->teams = [];
        if (file_exists($mappingFile)) {
            if ($this->logger instanceof LoggerInterface) {
                $this->logger->debug('LDAP: Attempting to map OU to teams.', ['dn' => $dn, 'mappingFile' => $mappingFile]);
            }

            try {
                $arMapping = Yaml::parse((string) file_get_contents($mappingFile));
                if (null === $arMapping || !is_array($arMapping)) {
                    if ($this->logger instanceof LoggerInterface) {
                        $this->logger->warning('LDAP: Team mapping file is empty or invalid.', ['mappingFile' => $mappingFile]);
                    }

                    return;
                }

                /** @var array<string, string> $arMapping */
                foreach ($arMapping as $group => $teamName) {
                    if (str_contains(strtolower($dn), 'ou=' . strtolower($group))) {
                        $this->teams[] = $teamName;
                        if ($this->logger instanceof LoggerInterface) {
                            $this->logger->info('LDAP: Mapped OU to team.', ['ou' => $group, 'team' => $teamName, 'dn' => $dn]);
                        }
                    }
                }

                if ([] === $this->teams && $this->logger instanceof LoggerInterface) {
                    $this->logger->info('LDAP: No matching OUs found in DN for team mapping.', ['dn' => $dn, 'mappingKeys' => array_keys($arMapping)]);
                }
            } catch (Exception $e) {
                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->error('LDAP: Failed to parse team mapping file.', [
                        'mappingFile' => $mappingFile,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } elseif ($this->logger instanceof LoggerInterface) {
            $this->logger->warning('LDAP: Team mapping file not found, skipping team assignment.', ['mappingFile' => $mappingFile]);
        }
    }

    /**
     * Normalize LDAP entry from getFirst() to expected array structure.
     *
     * Laminas LDAP stubs declare getFirst() returns array{dn: string}|null,
     * but actual runtime returns richer structure. This method validates
     * and transforms the raw result with proper type safety.
     *
     * @param mixed $rawEntry Raw entry from Collection::getFirst()
     *
     * @throws Exception When entry is null or invalid
     *
     * @return array<string, array<int, string>>
     */
    private function normalizeFirstEntry(mixed $rawEntry): array
    {
        if (null === $rawEntry || !is_array($rawEntry)) {
            throw new Exception('LDAP entry is null or not an array');
        }

        // The actual runtime type is richer than stubs declare.
        // Validate and transform each key-value pair.
        $normalized = [];
        foreach ($rawEntry as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (is_array($value)) {
                // Already in expected format: array<int, string>
                $stringValues = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $stringValues[] = $item;
                    } elseif (is_int($item) || is_float($item) || is_bool($item)) {
                        $stringValues[] = (string) $item;
                    }
                    // Skip non-stringable values
                }
                $normalized[$key] = $stringValues;
            } elseif (is_string($value)) {
                // Single string value, wrap in array
                $normalized[$key] = [$value];
            }
        }

        return $normalized;
    }
}
