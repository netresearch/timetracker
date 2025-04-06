<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use Laminas\Ldap\Ldap;
use Laminas\Ldap\Exception\LdapException;

/**
 * Client for LDAP login
 */
class LdapClient
{
    /**
     * @var string LDAP host name or IP.
     */
    protected $_host = '192.168.1.2';

    /**
     * @var integer LDAP host port.
     */
    protected $_port = 389;

    /**
     * @var string LDAP read user name.
     */
    protected $_readUser = 'readuser';

    /**
     * @var string LDAP read user password.
     */
    protected $_readPass = 'readuser';

    /**
     * @var string LDAP base DN.
     */
    protected $_baseDn = 'dc=netresearch,dc=nr';

    /**
     * @var string Accountname-Field in LDAP.
     */
    protected $_userNameField = 'sAMAccountName';

    /**
     * @var string LDAP user auth name.
     */
    protected $_userName;

    /**
     * @var string LDAP user auth password
     */
    protected $_userPass;

    /**
     * @var boolean Use SSL for LDAP-connection.
     */
    protected $_useSSL = false;

    /**
     * @var array
     */
    protected $teams = [];

    public function __construct(
        protected ?LoggerInterface $logger = null
    ) {
    }

    /**
     * @return string[] LDAP options
     */
    protected function getLdapOptions(): array
    {
        return [
            'useSsl'   => $this->_useSSL,
            'host'     => $this->_host,
            'username' => $this->_readUser,
            'password' => $this->_readPass,
            'baseDn'   => $this->_baseDn,
            'port'     => $this->_port
        ];
    }

    /**
     * Verify username by searching for it in LDAP.
     *
     * @throws \Exception
     * @throws LdapException
     *
     * @return array The search result (corresponding ldap entry)
     */
    protected function verifyUsername()
    {
        $ldapOptions = $this->getLdapOptions();
        $ldap = new Ldap($ldapOptions);

        putenv('LDAPTLS_REQCERT=never'); // SECURITY WARNING: Disables TLS certificate verification

        try {
            if ($this->logger) {
                $this->logger->debug('LDAP: Attempting read-only bind', [
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $this->_readUser,
                    'baseDn' => $this->_baseDn
                ]);
            }
            $ldap->bind();
            if ($this->logger) {
                $this->logger->info('LDAP: Read-only bind successful.');
            }
        } catch (LdapException $ldapException) {
            if ($this->logger) {
                $this->logger->error('LDAP: Read-only bind failed', [
                    'error' => $ldapException->getMessage(),
                    'code' => $ldapException->getCode(),
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $this->_readUser,
                    'baseDn' => $this->_baseDn
                ]);
            }
            // Re-throw original exception after logging
            throw new \Exception('No connection to LDAP: ' . $this->getLdapOptions()['host'] . ': ' . $ldapException->getMessage() . '', $ldapException->getCode(), $ldapException);
        }

        $searchFilter = '(' . $this->_userNameField . '=' . ldap_escape($this->_userName) . ')';
        if ($this->logger) {
            $this->logger->debug('LDAP: Searching for user', [
                'baseDn' => $this->_baseDn,
                'filter' => $searchFilter,
                'scope' => 'sub'
            ]);
        }

        /** @var \Laminas\Ldap\Collection $collection */
        $collection = $ldap->search(
            $searchFilter,
            $this->_baseDn,
            Ldap::SEARCH_SCOPE_SUB,
            ['cn', 'distinguishedName', 'dn']
        );

        if (!is_object($collection) || ($collection->count() === 0)) {
            if ($this->logger) {
                $this->logger->warning('LDAP: User search failed or returned no results.', [
                    'filter' => $searchFilter,
                    'baseDn' => $this->_baseDn
                ]);
            }
            throw new \Exception('Username unknown.');
        }

        if ($collection->count() > 1) {
             if ($this->logger) {
                $this->logger->warning('LDAP: User search returned multiple results. Using the first one.', [
                    'filter' => $searchFilter,
                    'baseDn' => $this->_baseDn,
                    'count' => $collection->count()
                ]);
            }
        }

        $ldapEntry = $collection->getFirst();
        if ($this->logger) {
            $returnedDn = $ldapEntry['distinguishedname'][0] ?? ($ldapEntry['dn'][0] ?? 'N/A');
            $this->logger->info('LDAP: User found.', [
                'dn_returned' => $returnedDn,
                'cn' => $ldapEntry['cn'][0] ?? 'N/A',
            ]);
        }

        $this->setTeamsByLdapResponse($ldapEntry);

        return $ldapEntry;
    }



    /**
     * Verify password by logging in to ldap using the user's name and password.
     *
     * @throws \Exception
     * @return boolean true
     */
    protected function verifyPassword(array $ldapEntry): bool
    {
        $userDn = $ldapEntry['distinguishedname'][0] ?? ($ldapEntry['dn'][0] ?? null);
        if (!$userDn) {
             if ($this->logger) {
                $this->logger->error('LDAP: Could not extract DN or distinguishedName from user entry.', ['entry' => $ldapEntry]);
             }
             throw new \Exception('Could not determine user DN for authentication.');
        }

        $ldapOptions = $this->getLdapOptions();
        // Override username/password for user bind attempt
        $ldapOptions['username'] = $userDn;
        $ldapOptions['password'] = $this->_userPass;

        $ldap = new Ldap($ldapOptions);

        try {
             if ($this->logger) {
                $this->logger->debug('LDAP: Attempting user bind.', [
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $userDn
                ]);
            }
            $ldap->bind();
            if ($this->logger) {
                $this->logger->info('LDAP: User bind successful.', ['bindDn' => $userDn]);
            }
        } catch (LdapException $ldapException) {
            if ($this->logger) {
                // Log level INFO or WARNING for failed login attempts might be appropriate
                // depending on security policy, but ERROR is safer for diagnostics.
                $this->logger->error('LDAP: User bind failed.', [
                    'error' => $ldapException->getMessage(),
                    'code' => $ldapException->getCode(), // Often contains LDAP-specific error codes
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'bindDn' => $userDn
                ]);
            }
            // Re-throw original exception after logging
            throw new \Exception('Login data could not be validated: ' . $ldapException->getMessage(), $ldapException->getCode(), $ldapException);
        }

        return true;
    }



    /**
     * Sets user auth name.
     *
     * @param string $username
     * @throws \Exception
     * @return $this
     */
    public function setUserName($username): static
    {
        if (!$username) {
            throw new \Exception(sprintf("Invalid user name: '%s'", $username));
        }

        // enforce ldap-style login names
        $this->_userName = str_replace(
            [' ', 'ä', 'ö', 'ü', 'ß', 'é'],
            ['.', 'ae', 'oe', 'ue', 'ss', 'e'],
            strtolower($username)
        );

        return $this;
    }



    /**
     * Sets user auth password.
     *
     * @param string $password
     * @return $this
     */
    public function setUserPass($password): static
    {
        $this->_userPass = $password;
        return $this;
    }



    /**
     * Sets LDAP host name or IP.
     *
     * @param string $host LDAP host name or IP.
     * @return $this
     */
    public function setHost($host): static
    {
        $this->_host = $host;
        return $this;
    }



    /**
     * Sets LDAP host port number.
     *
     * @param integer $port LAP host port number
     * @return $this
     */
    public function setPort($port): static
    {
        $this->_port = (int) $port;
        return $this;
    }



    /**
     * Sets LDAP read user name.
     *
     * @param string $readUser LDAP read user name
     * @return $this
     */
    public function setReadUser($readUser): static
    {
        $this->_readUser = $readUser;
        return $this;
    }



    /**
     * Sets LDAP read user password.
     *
     * @param string $readPass LDAP read user password
     * @return $this
     */
    public function setReadPass($readPass): static
    {
        $this->_readPass = $readPass;
        return $this;
    }



    /**
     * Sets LDAP base DN.
     *
     * @param string $base_dn LDAP base DN.
     * @return $this
     */
    public function setBaseDn($base_dn): static
    {
        $this->_baseDn = $base_dn;
        return $this;
    }



    /**
     * Determines whether SSL will be used for LDAP-connection or not
     *
     * @param boolean $useSSL
     * @return $this
     */
    public function setUseSSL($useSSL): static
    {
        $this->_useSSL = (boolean) $useSSL;
        return $this;
    }



    /**
     * Set LDAP field name used to identify the user account ("user.name")
     *
     * @param string $userNameField
     * @return $this
     */
    public function setUserNameField($userNameField): static
    {
        $this->_userNameField = $userNameField;
        return $this;
    }


    /**
     * Authenticate username and password at the LDAP server.
     *
     * @return true
     * @throws LdapException
     * @throws \Exception
     */
    public function login(): bool
    {
        return $this->verifyPassword(
            $this->verifyUsername()
        );
    }

    /**
     * @return array
     */
    public function getTeams()
    {
        return $this->teams;
    }

    protected function setTeamsByLdapResponse(array $ldapRespsonse)
    {
        $dn = $ldapRespsonse['distinguishedname'][0] ?? ($ldapRespsonse['dn'][0] ?? null);
        if (!$dn) {
            if ($this->logger) {
                $this->logger->warning('LDAP: Cannot map teams, DN is missing from LDAP response.', ['response' => $ldapRespsonse]);
            }
            return;
        }

        // Construct path relative to the project root or use a kernel parameter for flexibility
        // Assuming __DIR__ is src/Helper, adjust path accordingly.
        // Example using Kernel parameter (more robust):
        // $mappingFile = $this->projectDir . '/config/ldap_ou_team_mapping.yml';
        $mappingFile = __DIR__ . '/../../config/ldap_ou_team_mapping.yml'; // Adjusted relative path

        $this->teams = [];
        if (file_exists($mappingFile)) {
             if ($this->logger) {
                 $this->logger->debug('LDAP: Attempting to map OU to teams.', ['dn' => $dn, 'mappingFile' => $mappingFile]);
             }
            try {
                $arMapping = Yaml::parse(file_get_contents($mappingFile));
                if (!$arMapping || !is_array($arMapping)) {
                    if ($this->logger) {
                         $this->logger->warning('LDAP: Team mapping file is empty or invalid.', ['mappingFile' => $mappingFile]);
                     }
                    return;
                }

                foreach ($arMapping as $group => $teamName) {
                    // Check if the DN string contains the specific OU component
                    // Using preg_match for potentially more robust OU checking (e.g., ensuring it's a whole component)
                    // Simple str_contains might be sufficient if OU names are unique and simple.
                    // Example using preg_match: if (preg_match('/,ou=' . preg_quote($group, '/') . '(,|$)/i', $dn)) {
                    if (str_contains(strtolower($dn), 'ou=' . strtolower($group))) { // Case-insensitive check
                        $this->teams[] = $teamName;
                         if ($this->logger) {
                             $this->logger->info('LDAP: Mapped OU to team.', ['ou' => $group, 'team' => $teamName, 'dn' => $dn]);
                         }
                    }
                }
                 if (empty($this->teams) && $this->logger) {
                     $this->logger->info('LDAP: No matching OUs found in DN for team mapping.', ['dn' => $dn, 'mappingKeys' => array_keys($arMapping)]);
                 }

            } catch (\Exception $e) {
                if ($this->logger) {
                    $this->logger->error('LDAP: Failed to parse team mapping file.', [
                        'mappingFile' => $mappingFile,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        } else {
            if ($this->logger) {
                $this->logger->warning('LDAP: Team mapping file not found, skipping team assignment.', ['mappingFile' => $mappingFile]);
            }
        }
    }
}
