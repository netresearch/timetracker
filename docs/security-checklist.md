# Security Audit Checklist for Code Reviews

## Input Validation
- [ ] All user input is properly validated
- [ ] Use Symfony's Form component or Validator for validation
- [ ] Validate on both client and server sides
- [ ] Type casting/strict typing is used appropriately
- [ ] Regex patterns are safe and not susceptible to ReDoS attacks

## Output Encoding
- [ ] All output to browsers is properly escaped using Twig's auto-escaping or explicit escaping
- [ ] HTML, JS, CSS, and URL encoding is used in the appropriate contexts
- [ ] Content-Type headers are properly set
- [ ] X-Content-Type-Options: nosniff header is present

## Authentication & Authorization
- [ ] Authentication credentials are properly protected
- [ ] Passwords are hashed using strong algorithms (Argon2id preferred)
- [ ] Authorization checks are performed at each sensitive operation
- [ ] CSRF protection is enabled for all non-GET forms
- [ ] Rate limiting is implemented for login attempts

## Session Management
- [ ] Sessions are properly handled and secured
- [ ] Session data is not exposed in URLs
- [ ] Sensitive session data is encrypted
- [ ] Session timeouts are properly configured
- [ ] Secure and HttpOnly flags are set on cookies

## Database Interactions
- [ ] Parameterized queries/prepared statements are used for all database operations
- [ ] No raw SQL concatenated with user input
- [ ] ORM/Repository pattern is used correctly
- [ ] Entity validation is performed before persistence
- [ ] Database credentials are properly secured

## File Operations
- [ ] File uploads are properly validated and sanitized
- [ ] File permissions are set securely
- [ ] Path traversal vulnerabilities are prevented
- [ ] Temporary files are properly managed and deleted

## Error Handling & Logging
- [ ] Errors are caught and handled appropriately
- [ ] Sensitive data is not exposed in error messages
- [ ] Appropriate information is logged for security events
- [ ] Logs are protected from unauthorized access
- [ ] Custom exceptions are used where appropriate

## Secrets Management
- [ ] No hardcoded secrets in source code
- [ ] Sensitive configuration is in .env.local or environment variables
- [ ] API keys and credentials use proper access control
- [ ] Production secrets are different from development environments

## Dependency Management
- [ ] Dependencies are up-to-date
- [ ] No known vulnerabilities in dependencies
- [ ] Dependency sources are trustworthy
- [ ] Unused dependencies are removed

## API Security
- [ ] API endpoints use proper authentication
- [ ] Rate limiting and throttling implemented
- [ ] API requests and responses are validated
- [ ] Proper HTTP status codes are returned
- [ ] Security headers are properly set

## Additional Considerations
- [ ] Security-sensitive code has been manually reviewed
- [ ] Static analysis tools have been run on the code
- [ ] Security testing has been performed if applicable
- [ ] Health check endpoints don't leak sensitive information
- [ ] No debug information in production environment 