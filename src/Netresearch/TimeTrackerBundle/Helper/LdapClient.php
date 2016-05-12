<?php

namespace Netresearch\TimeTrackerBundle\Helper;

use \Zend\Ldap;

/*
 * Client for LDAP login
 */
class LdapClient
{
    /*
     * Host name
     */
	protected $_host = '192.168.1.4';

    /* 
     * base DN
     */
	protected $_baseDn = 'dc=netresearch,dc=nr';
	
	protected $_username;
    protected $_password;
    
    /**
     * Verify username by searching for it in LDAP
     * @throws Exception
     * @return array The search result (corresponding ldap entry)
     */
    protected function _verifyUsername()
    {
        $ldap = new Ldap\Ldap(array(
            'host'      => $this->_host,
            'username'  => 'readuser',
            'password'  => 'readuser',
            'baseDn'    => $this->_baseDn,
            'port'      => 389,
        ));
        
        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            throw new \Exception('No connection to LDAP.');
        }
        
        /* @var $result Ldap\Collection */
        $result = $ldap->search(
            '(sAMAccountName=' . $this->_username . ')',
            $this->_baseDn, Ldap\Ldap::SEARCH_SCOPE_SUB, array('cn', 'dn')
        );
        
        if (!is_object($result) || ($result->getFirst() == NULL)) {
            throw new \Exception('Username unknown.');
        }
        
        return $result->getFirst();
    }
    
    /**
     * Verify password by logging in to ldap using the user's name and password
     *
     * @param array $ldapEntry
     * @throws Exception
     * @return boolean true
     */
    protected function _verifyPassword($ldapEntry)
    {
        $ldap = new Ldap\Ldap(array(
    	    'host'      => $this->_host,
            'username'  => $ldapEntry['cn'][0],
            'password'  => $this->_password,
            'baseDn'    => $this->_baseDn,
            'port'      => 389,
        ));

        try {
            $ldap->bind();
        } catch (Ldap\Exception\LdapException $e) {
            throw new \Exception('Login data could not be validated.');
        }

        return true;
    }
    
    /**
     * 
     * @param string $username
     * @throws \Exception
     * @return \Netresearch\TimeTrackerBundle\Helper\LdapClient
     */
    public function setUsername($username)
    {
        if (!$username) {
            throw new \Exception("Invalid user name: '$username'");
        }

        // enforce ldap-style login names
        $this->_username = str_replace(
            array(' ','ä','ö','ü','ß','é'),
            array('.','ae','oe','ue','ss','e'),
            strtolower($username)
        );
        
        return $this;
    }

    /**
     * @param string $password
     * @return \Netresearch\TimeTrackerBundle\Helper\LdapClient
     */
    public function setPassword($password)
    {
        $this->_password = $password;
        return $this;
    }
    
    /**
     * @param string $host
     * @return \Netresearch\TimeTrackerBundle\Helper\LdapClient
     */
    public function setHost($host)
    {
        $this->_host = $host;
        return $this;
    }

    public function getHost()
    {
        return $this->_host;
    }

    /**
     * @param string $base_dn
     * @return \Netresearch\TimeTrackerBundle\Helper\LdapClient
     */
    public function setBaseDn($base_dn)
    {
        $this->_baseDn = $base_dn;
        return $this;
    }

    public function getBaseDn()
    {
        return $this->_baseDn;
    }
     
    /**
     * Authenticate username and password at the LDAP server.
     * @return true, throws \Exception otherwise
     */
    public function login()
    {
        $entry = $this->_verifyUsername();
        
        return $this->_verifyPassword($entry);
    }
}
