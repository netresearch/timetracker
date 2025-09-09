# Security Implementation Guide

**Last Updated**: 2025-01-20  
**Version**: 1.0  
**Classification**: Internal Development Documentation

## Table of Contents

1. [Overview](#overview)
2. [Authentication System](#authentication-system)
3. [Authorization Patterns](#authorization-patterns)
4. [Input Validation & Sanitization](#input-validation--sanitization)
5. [Token Security](#token-security)
6. [Recent Security Fixes](#recent-security-fixes)
7. [Security Best Practices](#security-best-practices)
8. [Security Testing](#security-testing)

## Overview

This guide documents the security implementation patterns used in the TimeTracker application. It serves as a reference for developers to understand and maintain the security posture of the application.

### Security Stack

- **Framework**: Symfony 7.3 with built-in security components
- **Authentication**: LDAP-based with secure password hashing
- **Session Management**: Symfony session with HTTPS-only cookies
- **Token Encryption**: AES-256-GCM for OAuth tokens
- **Input Validation**: DTO-based with Symfony validators

## Authentication System

### LDAP Authentication Flow

The application uses LDAP for user authentication with comprehensive security measures:

```php
// src/Security/LdapAuthenticator.php

class LdapAuthenticator extends AbstractLoginFormAuthenticator
{
    public function authenticate(Request $request): Passport
    {
        // 1. Extract and validate credentials
        $username = $this->sanitizeLdapInput((string) $request->request->get('_username'));
        $password = (string) $request->request->get('_password');
        
        // 2. Validate username format (prevent injection)
        if (!$this->isValidUsername($username)) {
            throw new CustomUserMessageAuthenticationException('Invalid username format.');
        }
        
        // 3. Perform LDAP authentication
        $this->ldapClientService->login();
        
        // 4. Create or retrieve local user
        // 5. Return authenticated passport
    }
}
```

### Password Security

**Configuration** (`config/packages/security.yaml`):
```yaml
security:
    password_hashers:
        App\Entity\User: 'auto'  # Uses bcrypt/argon2 automatically
```

**Security Features**:
- ✅ Automatic algorithm selection (bcrypt/argon2)
- ✅ Salt generation handled by Symfony
- ✅ Timing attack protection
- ✅ Password strength validation

### Session Security

```yaml
# config/packages/security.yaml
remember_me:
    secret: '%kernel.secret%'
    lifetime: 2592000  # 30 days
    path: /
    secure: true        # HTTPS required
    httponly: true      # Prevent XSS access
    samesite: strict    # CSRF protection
```

## Authorization Patterns

### Role-Based Access Control

```yaml
# config/packages/security.yaml
role_hierarchy:
    ROLE_ADMIN: ROLE_USER
    ROLE_SUPER_ADMIN: [ROLE_USER, ROLE_ADMIN, ROLE_ALLOWED_TO_SWITCH]

access_control:
    - { path: ^/admin, roles: ROLE_ADMIN }
    - { path: ^/, roles: IS_AUTHENTICATED_FULLY }
```

### Controller-Level Authorization

```php
// Recommended pattern using Symfony Security
#[Security("is_granted('ROLE_ADMIN')")]
public function adminAction(): Response
{
    // Admin-only functionality
}

// Entry-level authorization
public function canAccessEntry(User $user, Entry $entry): bool
{
    return $entry->getUserId() === $user->getId() 
        || $this->isGranted('ROLE_ADMIN');
}
```

### Critical Security Issue: Authorization Inconsistency

**Problem**: Mixed authorization checking patterns across controllers  
**Risk**: Potential authorization bypass  
**Solution**: Implement centralized authorization service

```php
// Proposed AuthorizationService
class AuthorizationService
{
    public function canAccessEntry(User $user, Entry $entry): bool
    {
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }
        
        return $entry->getUserId() === $user->getId();
    }
    
    public function canModifyProject(User $user, Project $project): bool
    {
        // Centralized project access logic
    }
}
```

## Input Validation & Sanitization

### LDAP Input Sanitization

```php
private function sanitizeLdapInput(string $input): string
{
    // RFC 4515 compliant LDAP escaping
    $metaChars = [
        '\\' => '\5c',   // Must be first
        '*' => '\2a',
        '(' => '\28',
        ')' => '\29',
        "\x00" => '\00',
        '/' => '\2f',    // Critical: Often missed
    ];
    
    return str_replace(
        array_keys($metaChars),
        array_values($metaChars),
        $input
    );
}
```

### DTO Validation

```php
// src/Dto/EntrySaveDto.php
class EntrySaveDto
{
    #[Assert\NotBlank]
    #[Assert\Type('integer')]
    public ?int $customerId = null;
    
    #[Assert\NotBlank]
    #[Assert\Regex(
        pattern: '/^[0-9]{2}:[0-9]{2}$/',
        message: 'Time must be in HH:MM format'
    )]
    public ?string $start = null;
}
```

### SQL Injection Prevention

**Current Issue**: Dynamic field interpolation in repositories  
**Solution**: Use parameterized queries exclusively

```php
// UNSAFE - Current issue
$qb->where("e.{$field} = :value");  // Field interpolation risk

// SAFE - Recommended approach
$allowedFields = ['day', 'user_id', 'project_id'];
if (!in_array($field, $allowedFields, true)) {
    throw new InvalidArgumentException('Invalid field');
}
$qb->where("e.{$field} = :value")
   ->setParameter('value', $value);
```

## Token Security

### OAuth Token Encryption

```php
// src/Service/Security/TokenEncryptionService.php
class TokenEncryptionService
{
    private const CIPHER_METHOD = 'aes-256-gcm';
    
    public function encryptToken(string $token): string
    {
        $iv = openssl_random_pseudo_bytes($ivLength);
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $token,
            self::CIPHER_METHOD,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag  // Authentication tag for tampering detection
        );
        
        return base64_encode($iv . $tag . $encrypted);
    }
}
```

### Token Storage Security

```php
// Storing encrypted tokens
$encryptedToken = $this->tokenEncryption->encryptToken($plainToken);
$userTicketSystem->setAccessToken($encryptedToken);

// Legacy token handling (backwards compatibility)
try {
    $decrypted = $this->tokenEncryption->decryptToken($stored);
} catch (Exception $e) {
    // Handle legacy unencrypted tokens
    // Log for migration tracking
}
```

## Recent Security Fixes

### 1. Password Hashing (CRITICAL - FIXED)

**Before**:
```yaml
password_hashers:
    App\Entity\User: 'plaintext'  # CRITICAL VULNERABILITY
```

**After**:
```yaml
password_hashers:
    App\Entity\User: 'auto'  # Secure hashing
```

### 2. Cookie Security (HIGH - FIXED)

**Before**:
```yaml
remember_me:
    secure: false  # Allowed HTTP transmission
```

**After**:
```yaml
remember_me:
    secure: true   # HTTPS required
    httponly: true
    samesite: strict
```

### 3. Performance Indexes (MEDIUM - FIXED)

Added indexes to prevent timing attacks on user lookups:
```sql
CREATE INDEX idx_entries_user_day ON entries (user_id, day DESC);
CREATE INDEX idx_entries_user_sync ON entries (user_id, synced_to_ticketsystem);
```

## Security Best Practices

### 1. Always Use Prepared Statements

```php
// Good
$qb->where('e.user = :user')
   ->setParameter('user', $userId);

// Bad
$qb->where("e.user = " . $userId);
```

### 2. Validate at Multiple Layers

```php
// 1. Client-side validation (for UX)
// 2. DTO validation (for structure)
#[Assert\NotBlank]
public ?string $ticket = null;

// 3. Business logic validation
if (!$this->isValidTicketFormat($ticket)) {
    throw new BusinessRuleException('Invalid ticket format');
}

// 4. Database constraints
ALTER TABLE entries ADD CONSTRAINT check_ticket_format 
    CHECK (ticket REGEXP '^[A-Z]+-[0-9]+$');
```

### 3. Implement Rate Limiting

```php
// Recommended implementation
#[RateLimiter('api', 'login')]
public function login(Request $request): Response
{
    // Prevents brute force attacks
}
```

### 4. Security Headers

```yaml
# config/packages/security.yaml
framework:
    headers:
        X-Frame-Options: DENY
        X-Content-Type-Options: nosniff
        Strict-Transport-Security: 'max-age=31536000; includeSubDomains'
        Content-Security-Policy: "default-src 'self'"
```

### 5. Audit Logging

```php
// Log security-relevant events
$this->logger->info('User login attempt', [
    'username' => substr($username, 0, 3) . '***',
    'ip' => $request->getClientIp(),
    'timestamp' => time(),
]);
```

## Security Testing

### Unit Tests for Security Features

```php
public function testLdapInputSanitization(): void
{
    $authenticator = new LdapAuthenticator();
    
    // Test injection attempts
    $malicious = "admin*)(uid=*))(|(uid=*";
    $sanitized = $authenticator->sanitizeLdapInput($malicious);
    
    $this->assertStringNotContainsString('*', $sanitized);
    $this->assertStringNotContainsString('(', $sanitized);
}
```

### Integration Tests

```php
public function testUnauthorizedAccessReturns403(): void
{
    $client = static::createClient();
    $client->request('GET', '/admin/users');
    
    $this->assertEquals(403, $client->getResponse()->getStatusCode());
}
```

### Security Scanning

```bash
# Dependency vulnerability scanning
composer audit

# Static security analysis
vendor/bin/psalm --taint-analysis

# OWASP dependency check
dependency-check --project timetracker --scan .
```

## Security Checklist for Developers

Before committing code, ensure:

- [ ] No hardcoded credentials or secrets
- [ ] All user input is validated and sanitized
- [ ] Database queries use parameterized statements
- [ ] Authentication checks are present on protected routes
- [ ] Sensitive data is encrypted at rest
- [ ] Error messages don't leak sensitive information
- [ ] Security headers are properly configured
- [ ] Rate limiting is implemented for public endpoints
- [ ] Audit logging covers security events
- [ ] Tests verify security controls

## Incident Response

In case of security incident:

1. **Immediate**: Disable affected functionality
2. **Assessment**: Determine scope and impact
3. **Containment**: Patch vulnerability
4. **Recovery**: Restore normal operations
5. **Post-Mortem**: Document and improve

## Resources

- [Symfony Security Documentation](https://symfony.com/doc/current/security.html)
- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://phpsecurity.readthedocs.io/)
- [LDAP Injection Prevention](https://cheatsheetseries.owasp.org/cheatsheets/LDAP_Injection_Prevention_Cheat_Sheet.html)

---

**Security Contact**: Report security issues to security@timetracker.internal  
**Last Security Audit**: 2025-01-20  
**Next Scheduled Review**: 2025-02-20