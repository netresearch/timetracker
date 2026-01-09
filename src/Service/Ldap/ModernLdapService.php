<?php

declare(strict_types=1);

namespace App\Service\Ldap;

use App\Service\TypeSafety\ArrayTypeHelper;
use Exception;
use InvalidArgumentException;
use Laminas\Ldap\Exception\LdapException;
use Laminas\Ldap\Ldap;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

use function assert;
use function count;
use function is_array;
use function is_scalar;
use function sprintf;
use function strlen;

/**
 * Modern LDAP service with improved encapsulation and configuration management.
 * Replaces the legacy LdapClientService with better practices.
 */
class ModernLdapService
{
    /** @var array<string, mixed> */
    private readonly array $config;

    private ?Ldap $ldap = null;

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
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
        } catch (LdapException $ldapException) {
            $this->log('LDAP authentication failed', [
                'username' => $username,
                'error' => $ldapException->getMessage(),
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
     *
     * @return array<string, mixed>|null
     */
    public function findUser(string $username): ?array
    {
        $username = $this->sanitizeLdapInput($username);

        try {
            $ldap = $this->getConnectionWithServiceAccount();

            $userNameField = ArrayTypeHelper::getString($this->config, 'userNameField', 'uid') ?? 'uid';
            $baseDn = ArrayTypeHelper::getString($this->config, 'baseDn', '') ?? '';
            $filter = sprintf('(%s=%s)', $userNameField, $username);
            $result = $ldap->search($filter, $baseDn);

            if (0 === $result->count()) {
                $this->log('User not found in LDAP', ['username' => $username]);

                return null;
            }

            $entry = $result->current();

            return $this->normalizeUserData($entry);
        } catch (LdapException $ldapException) {
            $this->log('LDAP search failed', [
                'username' => $username,
                'error' => $ldapException->getMessage(),
            ], 'error');

            throw $ldapException;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Searches for users by filter criteria.
     *
     * @param array<string, string> $criteria
     *
     * @throws LdapException
     *
     * @return array<int, array<string, mixed>>
     */
    public function searchUsers(array $criteria, int $limit = 100): array
    {
        try {
            $ldap = $this->getConnectionWithServiceAccount();

            $baseDn = ArrayTypeHelper::getString($this->config, 'baseDn', '') ?? '';
            $filter = $this->buildSearchFilter($criteria);
            $result = $ldap->search(
                $filter,
                $baseDn,
                Ldap::SEARCH_SCOPE_SUB,
                [],
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
        } catch (LdapException $ldapException) {
            $this->log('LDAP search failed', [
                'criteria' => $criteria,
                'error' => $ldapException->getMessage(),
            ], 'error');

            throw $ldapException;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Gets user groups from LDAP.
     *
     * @throws LdapException
     *
     * @return array<int, array<string, string>>
     */
    public function getUserGroups(string $username): array
    {
        $username = $this->sanitizeLdapInput($username);

        try {
            $ldap = $this->getConnectionWithServiceAccount();

            $userDn = $this->getUserDn($username);
            if (null === $userDn || '' === $userDn) {
                return [];
            }

            $filter = sprintf('(&(objectClass=group)(member=%s))', $userDn);
            $baseDn = ArrayTypeHelper::getString($this->config, 'baseDn', '') ?? '';
            $result = $ldap->search($filter, $baseDn, Ldap::SEARCH_SCOPE_SUB, ['cn', 'description']);

            $groups = [];
            foreach ($result as $entry) {
                assert(is_array($entry));
                $cn = $entry['cn'] ?? [];
                $description = $entry['description'] ?? [];
                assert(is_array($cn));
                assert(is_array($description));

                $groups[] = [
                    'name' => $cn[0] ?? '',
                    'description' => $description[0] ?? '',
                ];
            }

            return $groups;
        } catch (LdapException $ldapException) {
            $this->log('Failed to get user groups', [
                'username' => $username,
                'error' => $ldapException->getMessage(),
            ], 'error');

            throw $ldapException;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Tests LDAP connection with current configuration.
     *
     * @throws LdapException When LDAP operations fail
     * @throws Exception     When connection validation fails
     */
    public function testConnection(): bool
    {
        try {
            $ldap = $this->getConnectionWithServiceAccount();

            // Try a simple search to verify connection
            $baseDn = ArrayTypeHelper::getString($this->config, 'baseDn', '') ?? '';
            $result = $ldap->search(
                '(objectClass=*)',
                $baseDn,
                Ldap::SEARCH_SCOPE_BASE,
            );

            $this->log('LDAP connection test successful');

            return $result->count() > 0;
        } catch (LdapException $ldapException) {
            $this->log('LDAP connection test failed', ['error' => $ldapException->getMessage()], 'error');

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
        if (null === $this->ldap) {
            $options = $this->buildLdapOptions();
            $this->ldap = new Ldap($options);
        }

        return $this->ldap;
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
        $readUser = ArrayTypeHelper::getString($this->config, 'readUser', '') ?? '';
        $readPass = ArrayTypeHelper::getString($this->config, 'readPass', '') ?? '';
        $ldap->bind($readUser, $readPass);

        return $ldap;
    }

    /**
     * Disconnects from LDAP server.
     */
    private function disconnect(): void
    {
        if (null !== $this->ldap) {
            $this->ldap->disconnect();
            $this->ldap = null;
        }
    }

    /**
     * Builds LDAP connection options.
     *
     * @return array<string, mixed>
     */
    private function buildLdapOptions(): array
    {
        $options = [
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'baseDn' => $this->config['baseDn'],
            'useSsl' => $this->config['useSsl'],
        ];

        if (true === $this->config['useSsl']) {
            $options['useStartTls'] = false;
        }

        return $options;
    }

    /**
     * Builds user DN for authentication.
     */
    private function buildUserDn(string $username): string
    {
        $userNameField = ArrayTypeHelper::getString($this->config, 'userNameField', 'uid') ?? 'uid';
        $baseDn = ArrayTypeHelper::getString($this->config, 'baseDn', '') ?? '';

        return sprintf(
            '%s=%s,%s',
            $userNameField,
            $username,
            $baseDn,
        );
    }

    /**
     * Gets user DN by searching for the user.
     */
    private function getUserDn(string $username): ?string
    {
        $user = $this->findUser($username);

        return is_array($user) ? ArrayTypeHelper::getString($user, 'dn') : null;
    }

    /**
     * Builds LDAP search filter from criteria.
     *
     * @param array<string, string> $criteria
     */
    private function buildSearchFilter(array $criteria): string
    {
        $filters = [];

        foreach ($criteria as $field => $value) {
            $field = $this->sanitizeLdapInput($field);
            $value = $this->sanitizeLdapInput($value);

            // Support wildcards
            $filters[] = str_contains($value, '*') ? sprintf('(%s=%s)', $field, $value) : sprintf('(%s=*%s*)', $field, $value);
        }

        if (1 === count($filters)) {
            return $filters[0];
        }

        return '(&' . implode('', $filters) . ')';
    }

    /**
     * Normalizes LDAP user data to consistent format.
     *
     * @param array<string, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private function normalizeUserData(array $entry): array
    {
        $userNameField = ArrayTypeHelper::getString($this->config, 'userNameField', 'uid') ?? 'uid';

        return [
            'dn' => ArrayTypeHelper::getString($entry, 'dn', ''),
            'username' => is_array($entry[$userNameField] ?? null) ? ($entry[$userNameField][0] ?? '') : '',
            'email' => is_array($entry['mail'] ?? null) ? ($entry['mail'][0] ?? '') : '',
            'firstName' => is_array($entry['givenName'] ?? null) ? ($entry['givenName'][0] ?? '') : '',
            'lastName' => is_array($entry['sn'] ?? null) ? ($entry['sn'][0] ?? '') : '',
            'displayName' => is_array($entry['displayName'] ?? null) ? ($entry['displayName'][0] ?? '') : '',
            'department' => is_array($entry['department'] ?? null) ? ($entry['department'][0] ?? '') : '',
            'title' => is_array($entry['title'] ?? null) ? ($entry['title'][0] ?? '') : '',
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
            $input,
        );
    }

    /**
     * Validates input parameters.
     */
    private function validateInput(string $username, string $password): void
    {
        if ('' === $username || '0' === $username) {
            throw new InvalidArgumentException('Username cannot be empty');
        }

        if ('' === $password || '0' === $password) {
            throw new InvalidArgumentException('Password cannot be empty');
        }

        if (strlen($username) > 255) {
            throw new InvalidArgumentException('Username is too long');
        }
    }

    /**
     * Loads configuration from parameters.
     *
     * @return array<string, mixed>
     */
    private function loadConfiguration(): array
    {
        $port = $this->parameterBag->get('ldap_port');
        $portValue = is_scalar($port) ? (int) $port : 389;

        $useSsl = $this->parameterBag->get('ldap_usessl');
        $useSslValue = is_scalar($useSsl) && (bool) $useSsl;

        $host = $this->parameterBag->get('ldap_host');
        $hostValue = is_scalar($host) ? (string) $host : 'localhost';

        $readUser = $this->parameterBag->get('ldap_readuser');
        $readUserValue = is_scalar($readUser) ? (string) $readUser : '';

        $readPass = $this->parameterBag->get('ldap_readpass');
        $readPassValue = is_scalar($readPass) ? (string) $readPass : '';

        $baseDn = $this->parameterBag->get('ldap_basedn');
        $baseDnValue = is_scalar($baseDn) ? (string) $baseDn : '';

        $userNameField = $this->parameterBag->get('ldap_usernamefield');
        $userNameFieldValue = is_scalar($userNameField) ? (string) $userNameField : 'uid';

        return [
            'host' => $hostValue,
            'port' => $portValue,
            'readUser' => $readUserValue,
            'readPass' => $readPassValue,
            'baseDn' => $baseDnValue,
            'userNameField' => $userNameFieldValue,
            'useSsl' => $useSslValue,
        ];
    }

    /**
     * Logs messages with context.
     *
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = [], string $level = 'info'): void
    {
        if (! $this->logger instanceof LoggerInterface) {
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
