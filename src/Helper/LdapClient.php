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
    )
    {

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
        $ldap = new Ldap($this->getLdapOptions());

        putenv('LDAPTLS_REQCERT=never');

        try {
            $ldap->bind();
        } catch (LdapException $ldapException) {
            if ($this->logger instanceof \Psr\Log\LoggerInterface) {
                $this->logger->error('LDAP connection failed', [
                    'error' => $ldapException->getMessage(),
                    'host' => $this->_host,
                    'port' => $this->_port,
                    'useSSL' => $this->_useSSL,
                    'baseDn' => $this->_baseDn
                ]);
            }

            throw new \Exception('No connection to LDAP: ' . $this->getLdapOptions()['host'] . ': ' . $ldapException->getMessage() . '', $ldapException->getCode(), $ldapException);
        }

        /** @var \Laminas\Ldap\Collection $collection */
        $collection = $ldap->search(
            '(' . $this->_userNameField . '=' . ldap_escape($this->_userName) . ')',
            $this->_baseDn, Ldap::SEARCH_SCOPE_SUB, ['cn', 'dn']
        );

        if (!is_object($collection) || ($collection->getFirst() == NULL)) {
            throw new \Exception('Username unknown.');
        }

        $this->setTeamsByLdapResponse($collection->getFirst());

        return $collection->getFirst();
    }



    /**
     * Verify password by logging in to ldap using the user's name and password.
     *
     * @throws \Exception
     * @return boolean true
     */
    protected function verifyPassword(array $ldapEntry): bool
    {
        $ldapOptions = $this->getLdapOptions();
        $ldapOptions['username'] = $ldapEntry['dn'];
        $ldapOptions['password'] = $this->_userPass;

        $ldap = new Ldap($ldapOptions);

        try {
            $ldap->bind();
        } catch (LdapException $ldapException) {
            if ($this->logger instanceof \Psr\Log\LoggerInterface) {
                $this->logger->error($ldapException->getMessage());
            }

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
        $dn = $ldapRespsonse['dn'];
        $mappingFile = __DIR__ . '/../../../../app/config/ldap_ou_team_mapping.yml';

        $this->teams = [];
        if (file_exists($mappingFile)) {
            $arMapping = Yaml::parse(file_get_contents($mappingFile));
            if (!$arMapping) {
                return;
            }

            foreach ($arMapping as $group => $teamName) {
                if (strpos((string) $dn, 'ou=' . $group)) {
                    $this->teams[] = $teamName;
                }
            }
        }
    }
}
