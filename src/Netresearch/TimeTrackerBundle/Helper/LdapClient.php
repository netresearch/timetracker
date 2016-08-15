<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use \Zend\Ldap;

/*
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
     * @var \Symfony\Bridge\Monolog\Logger
     */
    protected $logger;


    /**
     * Verify username by searching for it in LDAP.
     *
     * @throws \Exception
     * @throws Ldap\Exception\LdapException
     *
     * @return array The search result (corresponding ldap entry)
     */
    protected function _verifyUsername()
    {
        $ldap = new Ldap\Ldap(array(
            'useSsl'    => $this->_useSSL,
            'host'      => $this->_host,
            'username'  => $this->_readUser,
            'password'  => $this->_readPass,
            'baseDn'    => $this->_baseDn,
            'port'      => $this->_port,
        ));

        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            throw new \Exception('No connection to LDAP.');
        }

        /* @var $result Ldap\Collection */
        $result = $ldap->search(
            '('.$this->_userNameField.'=' . ldap_escape($this->_userName) . ')',
            $this->_baseDn, Ldap\Ldap::SEARCH_SCOPE_SUB, array('cn', 'dn')
        );

        if (!is_object($result) || ($result->getFirst() == NULL)) {
            throw new \Exception('Username unknown.');
        }

        return $result->getFirst();
    }



    /**
     * Verify password by logging in to ldap using the user's name and password.
     *
     * @param array $ldapEntry
     * @throws \Exception
     * @return boolean true
     */
    protected function _verifyPassword(array $ldapEntry)
    {
        $ldap = new Ldap\Ldap(array(
            'host'      => $this->_host,
            'username'  => $ldapEntry['dn'],
            'password'  => $this->_userPass,
            'baseDn'    => $this->_baseDn,
            'port'      => $this->_port,
            'useSsl'    => $this->_useSSL,
        ));

        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            $this->logger->addError($e->getMessage());
            throw new \Exception('Login data could not be validated.');
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
            array(' ','ä','ö','ü','ß','é'),
            array('.','ae','oe','ue','ss','e'),
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
        $this->_useSSL = !empty($useSSL) && $this->_useSSL !== 0;
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
     */
    public function login()
    {
        return $this->_verifyPassword(
            $this->_verifyUsername()
        );
    }

    /**
     * @param \Symfony\Bridge\Monolog\Logger $logger
     */
    public function setLogger(\Symfony\Bridge\Monolog\Logger $logger)
    {
        $this->logger = $logger;
    }
}
