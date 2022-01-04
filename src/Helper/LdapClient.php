<?php
/**
 * Copyright (c) 2018. Netresearch GmbH & Co. KG | Netresearch DTT GmbH
 */

namespace App\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Yaml;
use \Zend\Ldap;

/**
 * Client for LDAP login
 */
class LdapClient
{
    /**
     * @var string LDAP host name or IP.
     */
	protected $_host = '192.168.1.4';

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

    public function __construct(protected LoggerInterface $logger)
				{
				}

    /**
     * @return string[] LDAP options
     */
    protected function getLdapOptions()
    {
        return [
            'useSsl'   => $this->_useSSL,
            'host'     => $this->_host,
            'username' => $this->_readUser,
            'password' => $this->_readPass,
            'baseDn'   => $this->_baseDn,
            'port'     => $this->_port,
        ];
    }

    /**
     * Verify username by searching for it in LDAP.
     *
     * @throws \Exception
     * @throws Ldap\Exception\LdapException
     *
     * @return array The search result (corresponding ldap entry)
     */
    protected function verifyUsername()
    {
        $ldap = new Ldap\Ldap($this->getLdapOptions());

        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            throw new \Exception('No connection to LDAP: ' . $this->getLdapOptions()['host'] . ': ' . $e->getMessage() . '');
        }

        /* @var $result Ldap\Collection */
        $result = $ldap->search(
            '(' . $this->_userNameField . '=' . ldap_escape($this->_userName) . ')',
            $this->_baseDn, Ldap\Ldap::SEARCH_SCOPE_SUB, array('cn', 'dn')
        );

        if (!is_object($result) || ($result->getFirst() == NULL)) {
            throw new \Exception('Username unknown.');
        }

        $this->setTeamsByLdapResponse($result->getFirst());

        return $result->getFirst();
    }



    /**
				 * Verify password by logging in to ldap using the user's name and password.
				 *
				 * @throws \Exception
				 * @return boolean true
				 */
				protected function verifyPassword(array $ldapEntry)
    {
        $ldapOptions = $this->getLdapOptions();
        $ldapOptions['username'] = $ldapEntry['dn'];
        $ldapOptions['password'] = $this->_userPass;

        $ldap = new Ldap\Ldap($ldapOptions);

        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            if ($this->logger) {
                $this->logger->addError($e->getMessage());
            }
            throw new \Exception('Login data could not be validated: ' . $e->getMessage());
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
    public function setUserName($username)
    {
        if (!$username) {
            throw new \Exception("Invalid user name: '$username'");
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
    public function setUserPass($password)
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
    public function setHost($host)
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
    public function setPort($port)
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
    public function setReadUser($readUser)
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
    public function setReadPass($readPass)
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
    public function setBaseDn($base_dn)
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
    public function setUseSSL($useSSL)
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
    public function setUserNameField($userNameField)
    {
        $this->_userNameField = $userNameField;
        return $this;
    }


    /**
     * Authenticate username and password at the LDAP server.
     *
     * @return true
     * @throws Ldap\Exception\LdapException
     * @throws \Exception
     */
    public function login()
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

    /**
     * @param array $ldapRespsonse
     */
    protected function setTeamsByLdapResponse($ldapRespsonse)
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
                if (strpos($dn, 'ou=' . $group)) {
                    $this->teams[] = $teamName;
                }
            }
        }
    }
}
