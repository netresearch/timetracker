# ADR-003: Security Architecture - LDAP Authentication and Token Encryption

## Status
Accepted

## Context and Problem Statement
The timetracker application required enterprise-grade authentication and secure token handling for:
- Employee authentication via existing Active Directory/LDAP infrastructure
- Secure storage of OAuth tokens for third-party integrations (JIRA)
- Protection against common security vulnerabilities
- Compliance with enterprise security policies

## Decision Drivers
- **Enterprise Integration**: Leverage existing LDAP/AD infrastructure
- **Security**: Protect sensitive authentication data and tokens
- **Compliance**: Meet enterprise security standards
- **User Experience**: Single sign-on capabilities
- **Maintainability**: Secure by default with minimal configuration

## Considered Options

### Option 1: Database Authentication (Rejected)
**Pros:**
- Simple implementation
- Full control over user management
- No external dependencies

**Cons:**
- Requires separate user management
- No SSO capabilities
- Doesn't leverage existing infrastructure
- Additional security maintenance burden

### Option 2: OAuth2/SAML Federation (Considered)
**Pros:**
- Modern authentication standards
- Good for cloud environments
- Strong security features

**Cons:**
- Complex setup for on-premise environments
- May not integrate with existing AD infrastructure
- Overkill for current requirements

### Option 3: LDAP Authentication + Secure Token Storage (Chosen)
**Pros:**
- Integrates with existing enterprise infrastructure
- Proven authentication method
- Secure token encryption for sensitive data
- Familiar to enterprise IT teams

**Cons:**
- LDAP dependency
- More complex than database auth
- Requires proper LDAP server configuration

## Decision Outcome
Implement enterprise security architecture with:

1. **LDAP Authentication** via Symfony Security Component
2. **AES-256-GCM Token Encryption** for OAuth tokens
3. **Secure session management** with enterprise settings
4. **Input sanitization** to prevent injection attacks

## Architecture Components

### LDAP Authentication System

#### LdapAuthenticator
```php
class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    // Custom authenticator with LDAP integration
    // Handles user creation and team assignment
    // Implements security best practices
}
```

#### Key Security Features
- **Input Sanitization**: RFC 4515 compliant LDAP injection prevention
- **Error Handling**: Non-disclosure of LDAP-specific errors
- **User Creation**: Automatic user provisioning with team assignment
- **Audit Logging**: Comprehensive authentication logging

#### Security Configuration
```yaml
# config/packages/security.yaml
security:
    firewalls:
        main:
            custom_authenticators:
                - App\Security\LdapAuthenticator
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 2592000  # 30 days
                secure: true       # HTTPS required
```

### Token Encryption Service

#### TokenEncryptionService
```php
class TokenEncryptionService
{
    private const string CIPHER_METHOD = 'aes-256-gcm';
    
    public function encryptToken(string $token): string
    public function decryptToken(string $encryptedToken): string
    public function rotateToken(string $encryptedToken): string
}
```

#### Encryption Features
- **Algorithm**: AES-256-GCM (authenticated encryption)
- **Key Derivation**: SHA-256 hash of app secret
- **IV Generation**: Random IV for each encryption
- **Authentication**: Built-in tag verification
- **Rotation**: Periodic token re-encryption

## Security Implementation Details

### LDAP Security Measures

#### Input Sanitization
```php
private function sanitizeLdapInput(string $input): string
{
    $metaChars = [
        '\\' => '\5c',   // Must be first
        '*' => '\2a',
        '(' => '\28',
        ')' => '\29',
        "\x00" => '\00',
        '/' => '\2f',
    ];
    return str_replace(array_keys($metaChars), array_values($metaChars), $input);
}
```

#### Username Validation
```php
private function isValidUsername(string $username): bool
{
    if (strlen($username) > 256) return false;
    return 1 === preg_match('/^[a-zA-Z0-9._@-]+$/', $username);
}
```

### Token Encryption Security

#### Authenticated Encryption Process
```php
public function encryptToken(string $token): string
{
    $iv = openssl_random_pseudo_bytes($ivLength);
    $tag = '';
    $encrypted = openssl_encrypt($token, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $encrypted);
}
```

#### Secure Decryption with Verification
```php
public function decryptToken(string $encryptedToken): string
{
    $combined = base64_decode($encryptedToken, true);
    $iv = substr($combined, 0, $ivLength);
    $tag = substr($combined, $ivLength, self::TAG_LENGTH);
    $encrypted = substr($combined, $ivLength + self::TAG_LENGTH);
    
    return openssl_decrypt($encrypted, self::CIPHER_METHOD, $key, OPENSSL_RAW_DATA, $iv, $tag);
}
```

## Configuration Requirements

### LDAP Configuration
```yaml
# .env
LDAP_HOST=ldap.company.com
LDAP_PORT=636
LDAP_USESSL=true
LDAP_BASEDN="DC=company,DC=com"
LDAP_READUSER="CN=timetracker,OU=ServiceAccounts,DC=company,DC=com"
LDAP_READPASS=secure_service_password
LDAP_USERNAMEFIELD=sAMAccountName
LDAP_CREATE_USER=true
```

### Encryption Configuration
```yaml
# .env
APP_SECRET=your-256-bit-secret-key
APP_ENCRYPTION_KEY=dedicated-encryption-key  # Optional, falls back to APP_SECRET
```

## Security Best Practices Implemented

### Authentication Security
1. **Password Protection**: Passwords never logged or stored
2. **Session Security**: Secure cookies, HTTPS enforcement
3. **Error Handling**: Generic error messages to prevent information disclosure
4. **Rate Limiting**: Via web server configuration (recommended)
5. **Audit Logging**: All authentication attempts logged

### Token Security
1. **Encryption at Rest**: All OAuth tokens encrypted in database
2. **Key Management**: Environment-based key configuration
3. **Token Rotation**: Periodic re-encryption capability
4. **Secure Transmission**: HTTPS enforcement for all token operations

### Input Validation
1. **LDAP Injection Prevention**: RFC 4515 compliant sanitization
2. **Username Validation**: Strict alphanumeric + safe characters only
3. **Length Limits**: Prevent buffer overflow attacks
4. **Type Safety**: Strict typing throughout authentication flow

## Threat Model Addressed

### Authentication Threats
- **LDAP Injection**: Mitigated by input sanitization
- **Credential Stuffing**: Relies on LDAP server protection
- **Session Hijacking**: HTTPS + secure cookie settings
- **Brute Force**: LDAP server + application logging

### Data Threats
- **Token Theft**: AES-256-GCM encryption protection
- **Man-in-the-Middle**: HTTPS enforcement
- **Data at Rest**: Encrypted token storage
- **Key Compromise**: Environment-based key management

## Compliance and Standards

### Standards Compliance
- **RFC 4515**: LDAP String Representation of Search Filters
- **NIST**: AES-256 encryption standards
- **OWASP**: Top 10 security practices
- **PSR-3**: Structured logging for security events

### Enterprise Requirements
- **SSO Integration**: Via LDAP/Active Directory
- **Audit Trails**: Comprehensive authentication logging
- **Token Security**: Enterprise-grade encryption
- **Access Control**: Role-based authorization

## Monitoring and Maintenance

### Security Monitoring
```php
// Authentication logging
$this->logger->info('LDAP authentication successful', ['username' => $userIdentifier]);
$this->logger->warning('Invalid username format attempted', ['username' => substr($username, 0, 3) . '***']);
$this->logger->error('LDAP authentication error', ['error_code' => $exception->getCode()]);
```

### Performance Monitoring
- LDAP connection times
- Token encryption/decryption latency
- Authentication success/failure rates
- Session duration statistics

### Maintenance Tasks
- **Quarterly**: Review LDAP configuration and connectivity
- **Monthly**: Analyze authentication logs for anomalies
- **Weekly**: Monitor token encryption performance
- **Daily**: Verify security configuration integrity

## Migration and Deployment

### Deployment Checklist
- [ ] LDAP connectivity tested
- [ ] Service account permissions verified
- [ ] SSL certificates configured
- [ ] Environment variables secured
- [ ] Logging endpoints configured
- [ ] Backup authentication method available

### Rollback Strategy
- Database authentication fallback available
- Configuration toggle for authentication method
- Emergency admin access maintained
- Full system restore procedure documented

## Related ADRs
- ADR-001: Service Layer Pattern Implementation
- ADR-004: Performance Optimization Strategy

## References
- [RFC 4515: LDAP Search Filters](https://tools.ietf.org/html/rfc4515)
- [NIST AES Specification](https://csrc.nist.gov/publications/detail/fips/197/final)
- [Symfony Security Component](https://symfony.com/doc/current/security.html)
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)