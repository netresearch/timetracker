<?php

declare(strict_types=1);

namespace App\Service\Ldap;

use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Ldap;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Modern LDAP service with improved encapsulation and configuration management.
 * Replaces the legacy LdapClientService with better practices.
 */
class ModernLdapService
{
    private readonly array $config;
    private ?Ldap $ldapConnection = null;
    
    public function __construct(
        private readonly ParameterBagInterface $params,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->config = $this->loadConfiguration();
    }

    /**
     * Authenticates a user against LDAP.
     * 
     * @throws LdapException
     */
    public function authenticate(string $username, string $password): bool
    {
        $this->validateInput($username, $password);
        
        try {
            $ldap = $this->getConnection();
            
            // Sanitize username to prevent LDAP injection
            $username = $this->sanitizeLdapInput($username);
            
            // Build DN for authentication
            $dn = $this->buildUserDn($username);
            
            $this->log('Attempting LDAP authentication', ['username' => $username]);
            
            // Attempt to bind with user credentials
            $ldap->bind($dn, $password);
            
            $this->log('LDAP authentication successful', ['username' => $username]);
            
            return true;
        } catch (LdapException $e) {
            $this->log('LDAP authentication failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ], 'warning');
            
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Searches for a user in LDAP directory.
     * 
     * @throws LdapException
     */
    public function findUser(string $username): ?array
    {
        $username = $this->sanitizeLdapInput($username);
        
        try {
            $ldap = $this->getConnectionWithServiceAccount();
            
            $filter = sprintf('(%s=%s)', $this->config['userNameField'], $username);
            $result = $ldap->search($this->config['baseDn'], $filter);
            
            if ($result->count() === 0) {
                $this->log('User not found in LDAP', ['username' => $username]);
                return null;
            }
            
            $entry = $result->current();
            
            return $this->normalizeUserData($entry);
        } catch (LdapException $e) {
            $this->log('LDAP search failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ], 'error');
            
            throw $e;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Searches for users by filter criteria.
     * 
     * @throws LdapException
     */
    public function searchUsers(array $criteria, int $limit = 100): array
    {
        try {
            $ldap = $this->getConnectionWithServiceAccount();
            
            $filter = $this->buildSearchFilter($criteria);
            $result = $ldap->search(
                $this->config['baseDn'],
                $filter,
                [],
                Ldap::SEARCH_SCOPE_SUB,
                $limit
            );
            
            $users = [];
            foreach ($result as $entry) {
                $users[] = $this->normalizeUserData($entry);
            }
            
            $this->log('LDAP search completed', [
                'filter' => $filter,
                'results' => count($users),
            ]);
            
            return $users;
        } catch (LdapException $e) {
            $this->log('LDAP search failed', [
                'criteria' => $criteria,
                'error' => $e->getMessage(),
            ], 'error');
            
            throw $e;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Gets user groups from LDAP.
     * 
     * @throws LdapException
     */
    public function getUserGroups(string $username): array
    {
        $username = $this->sanitizeLdapInput($username);
        
        try {
            $ldap = $this->getConnectionWithServiceAccount();
            
            $userDn = $this->getUserDn($username);
            if (!$userDn) {
                return [];
            }
            
            $filter = sprintf('(&(objectClass=group)(member=%s))', $userDn);
            $result = $ldap->search($this->config['baseDn'], $filter, ['cn', 'description']);
            
            $groups = [];
            foreach ($result as $entry) {
                $groups[] = [
                    'name' => $entry['cn'][0] ?? '',
                    'description' => $entry['description'][0] ?? '',
                ];
            }
            
            return $groups;
        } catch (LdapException $e) {
            $this->log('Failed to get user groups', [
                'username' => $username,
                'error' => $e->getMessage(),
            ], 'error');
            
            throw $e;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Tests LDAP connection with current configuration.
     */
    public function testConnection(): bool
    {
        try {
            $ldap = $this->getConnectionWithServiceAccount();
            
            // Try a simple search to verify connection
            $result = $ldap->search(
                $this->config['baseDn'],
                '(objectClass=*)',
                [],
                Ldap::SEARCH_SCOPE_BASE
            );
            
            $this->log('LDAP connection test successful');
            
            return $result->count() > 0;
        } catch (LdapException $e) {
            $this->log('LDAP connection test failed', ['error' => $e->getMessage()], 'error');
            
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Gets LDAP connection with user credentials.
     * 
     * @throws LdapException
     */
    private function getConnection(): Ldap
    {
        if ($this->ldapConnection === null) {
            $options = $this->buildLdapOptions();
            $this->ldapConnection = new Ldap($options);
        }
        
        return $this->ldapConnection;
    }

    /**
     * Gets LDAP connection with service account.
     * 
     * @throws LdapException
     */
    private function getConnectionWithServiceAccount(): Ldap
    {
        $ldap = $this->getConnection();
        
        // Bind with service account for searches
        $ldap->bind($this->config['readUser'], $this->config['readPass']);
        
        return $ldap;
    }

    /**
     * Disconnects from LDAP server.
     */
    private function disconnect(): void
    {
        if ($this->ldapConnection !== null) {
            $this->ldapConnection->disconnect();
            $this->ldapConnection = null;
        }
    }

    /**
     * Builds LDAP connection options.
     */
    private function buildLdapOptions(): array
    {
        $options = [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'baseDn' => $this->config['baseDn'],
            'useSsl' => $this->config['useSsl'],
        ];
        
        if ($this->config['useSsl']) {
            $options['useStartTls'] = false;
        }
        
        return $options;
    }

    /**
     * Builds user DN for authentication.
     */
    private function buildUserDn(string $username): string
    {
        return sprintf(
            '%s=%s,%s',
            $this->config['userNameField'],
            $username,
            $this->config['baseDn']
        );
    }

    /**
     * Gets user DN by searching for the user.
     */
    private function getUserDn(string $username): ?string
    {
        $user = $this->findUser($username);
        
        return $user['dn'] ?? null;
    }

    /**
     * Builds LDAP search filter from criteria.
     */
    private function buildSearchFilter(array $criteria): string
    {
        $filters = [];
        
        foreach ($criteria as $field => $value) {
            $field = $this->sanitizeLdapInput($field);
            $value = $this->sanitizeLdapInput($value);
            
            // Support wildcards
            if (str_contains($value, '*')) {
                $filters[] = sprintf('(%s=%s)', $field, $value);
            } else {
                $filters[] = sprintf('(%s=*%s*)', $field, $value);
            }
        }
        
        if (count($filters) === 1) {
            return $filters[0];
        }
        
        return '(&' . implode('', $filters) . ')';
    }

    /**
     * Normalizes LDAP user data to consistent format.
     */
    private function normalizeUserData(array $entry): array
    {
        return [
            'dn' => $entry['dn'] ?? '',
            'username' => $entry[$this->config['userNameField']][0] ?? '',
            'email' => $entry['mail'][0] ?? '',
            'firstName' => $entry['givenName'][0] ?? '',
            'lastName' => $entry['sn'][0] ?? '',
            'displayName' => $entry['displayName'][0] ?? '',
            'department' => $entry['department'][0] ?? '',
            'title' => $entry['title'][0] ?? '',
        ];
    }

    /**
     * Sanitizes LDAP input to prevent injection attacks.
     */
    private function sanitizeLdapInput(string $input): string
    {
        // LDAP special characters that need escaping
        $metaChars = [
            '\\' => '\5c',
            '*' => '\2a',
            '(' => '\28',
            ')' => '\29',
            "\x00" => '\00',
            '/' => '\2f',
        ];
        
        return str_replace(
            array_keys($metaChars),
            array_values($metaChars),
            $input
        );
    }

    /**
     * Validates input parameters.
     */
    private function validateInput(string $username, string $password): void
    {
        if (empty($username)) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }
        
        if (empty($password)) {
            throw new \InvalidArgumentException('Password cannot be empty');
        }
        
        if (strlen($username) > 255) {
            throw new \InvalidArgumentException('Username is too long');
        }
    }

    /**
     * Loads configuration from parameters.
     */
    private function loadConfiguration(): array
    {
        return [
            'host' => $this->params->get('ldap_host') ?: 'localhost',
            'port' => (int) ($this->params->get('ldap_port') ?: 389),
            'readUser' => $this->params->get('ldap_readuser') ?: '',
            'readPass' => $this->params->get('ldap_readpass') ?: '',
            'baseDn' => $this->params->get('ldap_basedn') ?: '',
            'userNameField' => $this->params->get('ldap_usernamefield') ?: 'uid',
            'useSsl' => (bool) ($this->params->get('ldap_usessl') ?: false),
        ];
    }

    /**
     * Logs messages with context.
     */
    private function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (!$this->logger) {
            return;
        }
        
        $context['service'] = 'ModernLdapService';
        
        match ($level) {
            'error' => $this->logger->error($message, $context),
            'warning' => $this->logger->warning($message, $context),
            'debug' => $this->logger->debug($message, $context),
            default => $this->logger->info($message, $context),
        };
    }
}