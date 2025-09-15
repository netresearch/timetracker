# ADR-004: Authentication Strategy (LDAP + Local)

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Security Team  

## Context

The TimeTracker application serves enterprise environments requiring integration with corporate identity systems while maintaining flexibility for organizations without LDAP infrastructure. The authentication system must support single sign-on, automatic user provisioning, role mapping, and provide reliable fallback mechanisms.

### Requirements
- **Enterprise Integration**: Seamless LDAP/Active Directory authentication
- **Automatic Provisioning**: Create users automatically from LDAP attributes
- **Role Mapping**: Map LDAP groups to TimeTracker roles (admin, user, controller)
- **Fallback Authentication**: Local authentication when LDAP is unavailable
- **Security**: Secure token management, session handling, audit logging
- **Performance**: Authentication response time <200ms, support 1000+ concurrent users

### Enterprise Environment Challenges
- Multiple LDAP servers across different organizational units
- Complex group hierarchies and nested group memberships
- LDAP server downtime requiring graceful degradation
- Mixed environments (some users in LDAP, others local-only)
- Compliance requirements for authentication logging

## Decision

We will implement a **hybrid LDAP-first authentication strategy** with **automatic local fallback** and **JWT token management**.

### Authentication Flow Architecture

**Primary Flow: LDAP Authentication**
```php
class LdapAuthenticator implements AuthenticatorInterface
{
    public function authenticate(Request $request): Passport
    {
        $credentials = $this->getCredentials($request);
        
        try {
            // 1. Attempt LDAP authentication
            $ldapUser = $this->ldapService->authenticate(
                $credentials['username'], 
                $credentials['password']
            );
            
            // 2. Provision/update local user from LDAP attributes
            $user = $this->userProvisioningService->provisionFromLdap($ldapUser);
            
            // 3. Map LDAP groups to application roles
            $roles = $this->roleMapper->mapLdapGroups($ldapUser->getGroups());
            $user->setRoles($roles);
            
            return new SelfValidatingPassport(new UserBadge($user->getUsername()));
            
        } catch (LdapException $e) {
            // 4. Fallback to local authentication
            return $this->localAuthenticator->authenticate($request);
        }
    }
}
```

**Fallback Flow: Local Authentication**
```php
class LocalAuthenticator implements AuthenticatorInterface
{
    public function authenticate(Request $request): Passport
    {
        $credentials = $this->getCredentials($request);
        
        $user = $this->userRepository->findByUsername($credentials['username']);
        
        if (!$user || !$user->isLocalAuthEnabled()) {
            throw new AuthenticationException('Local authentication disabled');
        }
        
        return new Passport(
            new UserBadge($credentials['username']),
            new PasswordCredentials($credentials['password']),
            [new CsrfTokenBadge('authenticate', $credentials['_token'])]
        );
    }
}
```

## Implementation Details

### LDAP Configuration Strategy
```yaml
# config/services.yaml
parameters:
    # Primary LDAP server
    ldap_host: '%env(LDAP_HOST)%'
    ldap_port: '%env(int:LDAP_PORT)%'
    ldap_basedn: '%env(LDAP_BASEDN)%'
    ldap_usernamefield: '%env(LDAP_USERNAMEFIELD)%'
    ldap_usessl: '%env(bool:LDAP_USESSL)%'
    
    # Fallback LDAP servers (comma-separated)
    ldap_fallback_hosts: '%env(LDAP_FALLBACK_HOSTS)%'
    
    # User provisioning settings
    ldap_create_user: '%env(bool:LDAP_CREATE_USER)%'
    ldap_update_user: '%env(bool:LDAP_UPDATE_USER)%'
```

### Modern LDAP Service Implementation
```php
class ModernLdapService
{
    private LdapInterface $ldap;
    private array $connectionPool = [];
    
    public function authenticate(string $username, string $password): LdapUser
    {
        $ldapConnection = $this->getConnection();
        
        // Bind with service account first
        if (!$ldapConnection->bind($this->serviceUser, $this->servicePassword)) {
            throw new LdapException('Service account authentication failed');
        }
        
        // Search for user
        $userDn = $this->findUserDn($username);
        if (!$userDn) {
            throw new LdapException("User {$username} not found in LDAP");
        }
        
        // Authenticate user with their credentials
        if (!$ldapConnection->bind($userDn, $password)) {
            throw new LdapException('Invalid credentials');
        }
        
        // Fetch user attributes and group memberships
        return $this->fetchUserAttributes($userDn);
    }
    
    private function getConnection(): LdapInterface
    {
        $hosts = array_merge(
            [$this->primaryHost],
            explode(',', $this->fallbackHosts ?? '')
        );
        
        foreach ($hosts as $host) {
            try {
                $connection = Ldap::create('ext_ldap', [
                    'host' => trim($host),
                    'port' => $this->port,
                    'encryption' => $this->useSSL ? 'ssl' : 'none',
                    'options' => [
                        'protocol_version' => 3,
                        'referrals' => false,
                        'network_timeout' => 5,
                    ]
                ]);
                
                $this->connectionPool[$host] = $connection;
                return $connection;
                
            } catch (LdapException $e) {
                $this->logger->warning("LDAP connection failed for {$host}", [
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }
        
        throw new LdapException('All LDAP servers unavailable');
    }
}
```

### User Provisioning Service
```php
class UserProvisioningService
{
    public function provisionFromLdap(LdapUser $ldapUser): User
    {
        $user = $this->userRepository->findOneBy(['username' => $ldapUser->getUsername()]);
        
        if (!$user) {
            if (!$this->autoCreateUsers) {
                throw new AuthenticationException('User creation disabled');
            }
            
            $user = new User();
            $user->setUsername($ldapUser->getUsername());
            $user->setAuthenticationMethod('ldap');
        }
        
        // Update user attributes from LDAP
        $this->updateUserFromLdap($user, $ldapUser);
        
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        
        return $user;
    }
    
    private function updateUserFromLdap(User $user, LdapUser $ldapUser): void
    {
        $user->setEmail($ldapUser->getEmail());
        $user->setFirstName($ldapUser->getFirstName());
        $user->setLastName($ldapUser->getLastName());
        $user->setDepartment($ldapUser->getDepartment());
        $user->setLastLdapSync(new \DateTime());
        
        // Update roles based on LDAP group membership
        $roles = $this->roleMapper->mapLdapGroups($ldapUser->getGroups());
        $user->setRoles(array_unique(array_merge(['ROLE_USER'], $roles)));
    }
}
```

### Role Mapping Configuration
```php
class LdapRoleMapper
{
    private array $groupRoleMap = [
        'CN=TimeTracker-Admins,OU=Groups,DC=company,DC=com' => 'ROLE_ADMIN',
        'CN=TimeTracker-Controllers,OU=Groups,DC=company,DC=com' => 'ROLE_CONTROLLER',
        'CN=Project-Leaders,OU=Groups,DC=company,DC=com' => 'ROLE_PROJECT_LEADER',
        'CN=Developers,OU=Groups,DC=company,DC=com' => 'ROLE_USER',
    ];
    
    public function mapLdapGroups(array $ldapGroups): array
    {
        $roles = [];
        
        foreach ($ldapGroups as $groupDn) {
            if (isset($this->groupRoleMap[$groupDn])) {
                $roles[] = $this->groupRoleMap[$groupDn];
            }
        }
        
        return $roles;
    }
}
```

## Consequences

### Positive
- **Enterprise Ready**: Seamless integration with corporate identity systems
- **High Availability**: Multiple fallback mechanisms ensure authentication availability
- **Automatic Management**: User provisioning reduces administrative overhead
- **Security**: LDAP provides centralized authentication and password policies
- **Flexibility**: Mixed authentication modes support diverse organizational needs
- **Audit Compliance**: Comprehensive authentication logging meets enterprise requirements

### Negative
- **Complexity**: Multiple authentication paths increase system complexity
- **Dependencies**: LDAP server availability affects user experience
- **Configuration**: Complex LDAP setup requires specialized knowledge
- **Synchronization**: LDAP attribute changes need periodic sync to stay current
- **Testing**: Mock LDAP servers required for comprehensive testing

### Security Implementation

**Token Management:**
```php
class TokenEncryptionService
{
    public function generateSecureToken(User $user): string
    {
        $payload = [
            'user_id' => $user->getId(),
            'username' => $user->getUsername(),
            'roles' => $user->getRoles(),
            'issued_at' => time(),
            'expires_at' => time() + 3600, // 1 hour
            'auth_method' => $user->getAuthenticationMethod(),
        ];
        
        return JWT::encode($payload, $this->secretKey, 'HS256');
    }
    
    public function validateToken(string $token): User
    {
        try {
            $payload = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            
            if ($payload->expires_at < time()) {
                throw new AuthenticationException('Token expired');
            }
            
            return $this->userRepository->find($payload->user_id);
            
        } catch (Exception $e) {
            throw new AuthenticationException('Invalid token');
        }
    }
}
```

**Session Security:**
```yaml
# config/packages/security.yaml
security:
    session_fixation_strategy: migrate
    session_cookie_secure: true
    session_cookie_httponly: true
    session_cookie_samesite: strict
    remember_me:
        secret: '%kernel.secret%'
        lifetime: 2592000  # 30 days
        secure: true
        httponly: true
```

### Performance Optimizations

**Connection Pooling:**
- Reuse LDAP connections across requests
- Connection health checks before reuse
- Automatic connection cleanup on failures

**Caching Strategy:**
- Cache LDAP group memberships for 15 minutes
- Cache user attributes for 5 minutes
- Invalidate cache on explicit user updates

**Monitoring and Alerting:**
```php
class AuthenticationMetrics
{
    public function recordAuthentication(string $method, bool $success, float $duration): void
    {
        $this->metrics->increment('auth.attempts', ['method' => $method, 'success' => $success]);
        $this->metrics->histogram('auth.duration', $duration, ['method' => $method]);
        
        if ($duration > 1.0) { // Slow authentication
            $this->logger->warning('Slow authentication detected', [
                'method' => $method,
                'duration' => $duration
            ]);
        }
    }
}
```

### Migration and Testing Strategy

**Migration Path:**
1. **Phase 1**: Implement LDAP authentication with manual user creation
2. **Phase 2**: Add automatic user provisioning from LDAP attributes
3. **Phase 3**: Implement group-based role mapping
4. **Phase 4**: Add fallback authentication and error handling
5. **Phase 5**: Performance optimization and comprehensive monitoring

**Testing Strategy:**
- Unit tests with mock LDAP services
- Integration tests with containerized LDAP server
- Load testing with 1000+ concurrent authentications
- Failure scenario testing (LDAP server down, network issues)

This hybrid authentication strategy provides enterprise-grade security and integration while maintaining system availability and user experience across diverse deployment environments.