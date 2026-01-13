# Configuration Guide

**Complete guide to configuring TimeTracker for different environments and use cases**

---

## Table of Contents

1. [Environment Configuration](#environment-configuration)
2. [LDAP/Active Directory Setup](#ldapactive-directory-setup)
3. [Jira Integration](#jira-integration)
4. [Database Configuration](#database-configuration)
5. [Security Settings](#security-settings)
6. [Performance Tuning](#performance-tuning)
7. [Logging & Monitoring](#logging--monitoring)
8. [Multi-tenant Setup](#multi-tenant-setup)
9. [Troubleshooting](#troubleshooting)

---

## Environment Configuration

### Basic Environment Variables

```env
# .env.local - Core application settings

# Application Environment
APP_ENV=prod                    # prod, dev, test
APP_DEBUG=0                     # 0 for production, 1 for development
APP_SECRET=your-32-character-secret-key

# Database Connection
DATABASE_URL="mysql://user:password@127.0.0.1:3306/timetracker?charset=utf8mb4&serverVersion=8.0"

# Encryption Key (for sensitive token storage)
APP_ENCRYPTION_KEY=your-base64-encoded-key

# Timezone Configuration
APP_TIMEZONE=Europe/Berlin      # Default timezone for the application
```

### Environment-Specific Configurations

#### Development Environment
```env
# .env.local (development)
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=dev-secret-not-for-production

# Development Database
DATABASE_URL="mysql://root:dev@127.0.0.1:3306/timetracker_dev"

# Enable profiler and debug tools
SYMFONY_ENV=dev
WEB_PROFILER_ENABLED=1

# Relaxed security for development
TRUSTED_PROXY_ALL=1
CORS_ALLOW_ORIGIN=*

# Development LDAP (optional)
LDAP_HOST=ldap-dev.company.local
LDAP_CREATE_USER=true           # Auto-create users in dev
```

#### Production Environment
```env
# .env.local (production)
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=production-secret-min-32-chars

# Production Database with connection pooling
DATABASE_URL="mysql://timetracker:secure_password@db-server:3306/timetracker?charset=utf8mb4&serverVersion=8.0"

# Security Settings
TRUSTED_PROXY_LIST=["10.0.0.0/8","172.16.0.0/12","192.168.0.0/16"]
TRUSTED_PROXY_ALL=0

# Performance Settings
OPCACHE_ENABLED=1
APCU_ENABLED=1

# Production LDAP
LDAP_HOST=ldap.company.com
LDAP_CREATE_USER=false          # Manual user creation in prod
LDAP_USESSL=true               # Use SSL for production
```

#### Testing Environment
```env
# .env.test
APP_ENV=test
APP_DEBUG=0
DATABASE_URL="mysql://unittest:unittest@127.0.0.1:3307/unittest"

# Disable external integrations in tests
LDAP_HOST=mock://ldap.test
JIRA_INTEGRATION_ENABLED=false
WEBHOOK_DELIVERY_ENABLED=false
MAIL_DSN=null://null

# Test-specific settings
SYMFONY_DEPRECATIONS_HELPER=weak
KERNEL_CLASS=App\Kernel
```

---

## LDAP/Active Directory Setup

### Basic LDAP Configuration

```env
# LDAP Connection Settings
LDAP_HOST=ldap.company.com
LDAP_PORT=389                   # 389 for LDAP, 636 for LDAPS
LDAP_USESSL=false              # true for LDAPS
LDAP_STARTTLS=false            # true for StartTLS
LDAP_VERSION=3                 # LDAP protocol version

# Authentication Settings
LDAP_BASEDN="dc=company,dc=com"
LDAP_READUSER="cn=readonly,ou=service,dc=company,dc=com"
LDAP_READPASS="readonly_password"

# User Mapping
LDAP_USERNAMEFIELD=uid         # or sAMAccountName for AD
LDAP_EMAILFIELD=mail
LDAP_FULLNAMEFIELD=cn
LDAP_USERFILTER="(objectClass=person)"

# User Management
LDAP_CREATE_USER=true          # Auto-create users on first login
LDAP_UPDATE_USER=true          # Update user info from LDAP
LDAP_DEFAULT_ROLE=ROLE_DEV     # Default role for new users
```

### Active Directory Configuration

```env
# Microsoft Active Directory Setup
LDAP_HOST=ad.company.com
LDAP_PORT=389
LDAP_USESSL=false
LDAP_STARTTLS=true             # Recommended for AD

# AD-specific settings
LDAP_BASEDN="dc=company,dc=com"
LDAP_READUSER="cn=TimeTracker Service,ou=Service Accounts,dc=company,dc=com"
LDAP_READPASS="service_account_password"

# AD User Mapping
LDAP_USERNAMEFIELD=sAMAccountName
LDAP_EMAILFIELD=mail
LDAP_FULLNAMEFIELD=displayName
LDAP_USERFILTER="(&(objectClass=user)(objectCategory=person))"

# Group-based Role Mapping
LDAP_GROUP_MAPPING='{"cn=TimeTracker_Admins,ou=Groups,dc=company,dc=com":"ROLE_PL","cn=TimeTracker_Controllers,ou=Groups,dc=company,dc=com":"ROLE_CTL"}'
```

### Advanced LDAP Features

```env
# Team Assignment from LDAP Groups
LDAP_TEAM_MAPPING='{"cn=Development,ou=Teams,dc=company,dc=com":"1","cn=Marketing,ou=Teams,dc=company,dc=com":"2"}'

# Multiple LDAP Servers (failover)
LDAP_HOSTS=["ldap1.company.com","ldap2.company.com"]

# Custom LDAP Attributes
LDAP_CUSTOM_ATTRIBUTES='{"department":"ou","employeeId":"employeeNumber"}'

# LDAP Connection Pooling
LDAP_POOL_SIZE=10
LDAP_POOL_TIMEOUT=30

# Certificate Validation (for LDAPS/StartTLS)
LDAP_TLS_CERT_FILE="/path/to/cert.pem"
LDAP_TLS_KEY_FILE="/path/to/key.pem"
LDAP_TLS_CA_CERT_FILE="/path/to/ca.pem"
```

### LDAP Testing & Validation

```bash
# Test LDAP connection
php bin/console app:ldap:test

# Validate user authentication
php bin/console app:ldap:authenticate username password

# Test group membership
php bin/console app:ldap:groups username

# Import users from LDAP
php bin/console app:ldap:import --dry-run
php bin/console app:ldap:import --limit=100
```

---

## Jira Integration

### OAuth 2.0 Configuration

```env
# JIRA OAuth Settings
JIRA_INTEGRATION_ENABLED=true
JIRA_BASE_URL=https://company.atlassian.net
JIRA_OAUTH_CONSUMER_KEY=timetracker
JIRA_OAUTH_PRIVATE_KEY_FILE="/var/www/html/config/jira/private.pem"
JIRA_OAUTH_PUBLIC_KEY_FILE="/var/www/html/config/jira/public.pem"

# Worklog Settings
JIRA_WORKLOG_AUTO_SYNC=true
JIRA_WORKLOG_COMMENT_TEMPLATE="Time tracked via TimeTracker"
JIRA_WORKLOG_VISIBILITY=developers    # or "all", "administrators"

# Rate Limiting
JIRA_API_RATE_LIMIT=100              # requests per minute
JIRA_BULK_SYNC_BATCH_SIZE=50         # entries per batch
```

### Setting up Jira OAuth

1. **Generate RSA Key Pair**:
```bash
# Generate private key
openssl genrsa -out jira_private.pem 1024

# Generate public key
openssl req -newkey rsa:1024 -x509 -key jira_private.pem -out jira_public.cer -days 365

# Convert to PKCS#8 format
openssl pkcs8 -topk8 -nocrypt -in jira_private.pem -out jira_private.pkcs8

# Extract public key for Jira
openssl x509 -pubkey -noout -in jira_public.cer > jira_public.pem
```

2. **Jira Application Link Setup**:
```
URL: https://timetracker.company.com
Application Name: TimeTracker
Application Type: Generic Application
Consumer Key: timetracker
Consumer Name: TimeTracker
Public Key: [contents of jira_public.pem]
```

3. **Database Configuration**:
```sql
-- Create ticket system in TimeTracker
INSERT INTO ticket_systems (name, type, url, timebooking, oauth_consumer_key, oauth_consumer_secret) 
VALUES ('Company Jira', 'jira', 'https://company.atlassian.net/browse/%s', 1, 'timetracker', '[private_key_content]');

-- Assign to projects
INSERT INTO project_ticket_systems (project_id, ticket_system_id) 
VALUES (1, 1);
```

### Advanced Jira Features

```env
# Project Mapping (External → Internal)
JIRA_PROJECT_MAPPING='{"EXT":"INTERNAL","CLIENT-123":"COMP-456"}'

# Custom Field Mapping
JIRA_CUSTOM_FIELDS='{"timetracker_entry_id":"customfield_10001","employee_id":"customfield_10002"}'

# Webhook Configuration
JIRA_WEBHOOK_SECRET=your-webhook-secret
JIRA_WEBHOOK_URL=https://timetracker.company.com/webhook/jira

# Error Handling
JIRA_MAX_RETRIES=3
JIRA_RETRY_DELAY=5               # seconds
JIRA_TIMEOUT=30                  # seconds

# Sync Filters
JIRA_SYNC_MIN_DURATION=900       # Don't sync entries < 15 minutes
JIRA_SYNC_MAX_AGE_DAYS=30        # Don't sync entries older than 30 days
```

---

## Database Configuration

### MySQL/MariaDB Optimization

```ini
# my.cnf optimizations for TimeTracker
[mysql]
default-character-set = utf8mb4

[mysqld]
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci

# Performance Settings
innodb_buffer_pool_size = 1G
innodb_log_file_size = 256M
innodb_flush_log_at_trx_commit = 2
innodb_flush_method = O_DIRECT

# Query Cache (if using MySQL < 8.0)
query_cache_size = 128M
query_cache_limit = 2M

# Connection Settings
max_connections = 200
max_connect_errors = 10000
connect_timeout = 60
interactive_timeout = 600
wait_timeout = 600

# TimeTracker-specific optimizations
sql_mode = "STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO"
```

### Database Connection Configuration

```env
# Primary Database
DATABASE_URL="mysql://timetracker:password@mysql-primary:3306/timetracker?charset=utf8mb4&serverVersion=8.0.32"

# Read Replica (optional)
DATABASE_READ_URL="mysql://timetracker_ro:password@mysql-replica:3306/timetracker?charset=utf8mb4&serverVersion=8.0.32"

# Connection Pool Settings
DATABASE_POOL_SIZE=20
DATABASE_POOL_TIMEOUT=30
DATABASE_IDLE_TIMEOUT=300

# SSL Configuration
DATABASE_SSL_CA="/path/to/ca-cert.pem"
DATABASE_SSL_CERT="/path/to/client-cert.pem"
DATABASE_SSL_KEY="/path/to/client-key.pem"
```

### Performance Indexes

```sql
-- Essential indexes for TimeTracker performance
-- Apply via: mysql timetracker < sql/performance_indexes.sql

-- Entry queries optimization
CREATE INDEX idx_entries_user_date ON entries (user_id, day);
CREATE INDEX idx_entries_project_date ON entries (project_id, day);
CREATE INDEX idx_entries_ticket ON entries (ticket);
CREATE INDEX idx_entries_updated ON entries (updated_at);

-- Reporting queries optimization  
CREATE INDEX idx_entries_date_range ON entries (day, user_id, project_id);
CREATE INDEX idx_entries_duration ON entries (duration, day);

-- JIRA integration optimization
CREATE INDEX idx_entries_sync_status ON entries (jira_sync_status, updated_at);
CREATE INDEX idx_entries_external_id ON entries (jira_worklog_id);

-- User/Team queries
CREATE INDEX idx_users_active ON users (active, username);
CREATE INDEX idx_team_users ON team_users (team_id, user_id);

-- Audit and logging
CREATE INDEX idx_audit_user_action ON audit_logs (user_id, action, created_at);
```

---

## Security Settings

### Authentication & Authorization

```env
# JWT Token Configuration
JWT_SECRET_KEY=your-jwt-secret-key-min-32-chars
JWT_PUBLIC_KEY_PATH="/path/to/public.pem"
JWT_PRIVATE_KEY_PATH="/path/to/private.pem"
JWT_PASSPHRASE=optional-private-key-passphrase

# Token Lifetimes
JWT_ACCESS_TOKEN_TTL=3600        # 1 hour
JWT_REFRESH_TOKEN_TTL=86400      # 24 hours
JWT_REMEMBER_ME_TTL=2592000      # 30 days

# Session Configuration
SESSION_COOKIE_SECURE=true       # Only over HTTPS in production
SESSION_COOKIE_HTTPONLY=true     # Prevent XSS access
SESSION_COOKIE_SAMESITE=strict   # CSRF protection
SESSION_LIFETIME=1800            # 30 minutes
```

### CORS Configuration

```env
# CORS Settings for API access
CORS_ALLOW_ORIGIN=https://frontend.company.com,https://admin.company.com
CORS_ALLOW_HEADERS=Accept,Authorization,Content-Type,X-Requested-With
CORS_ALLOW_METHODS=GET,POST,PUT,DELETE,OPTIONS,PATCH
CORS_EXPOSE_HEADERS=X-Total-Count,X-Page-Count
CORS_MAX_AGE=3600
CORS_ALLOW_CREDENTIALS=true
```

### Rate Limiting

```env
# API Rate Limiting
RATE_LIMIT_ENABLED=true
RATE_LIMIT_STORAGE=redis://redis:6379/1

# Rate Limits by Role
RATE_LIMIT_DEV=1000              # requests per hour
RATE_LIMIT_CTL=2000
RATE_LIMIT_PL=5000
RATE_LIMIT_SERVICE=10000

# Special Endpoints
RATE_LIMIT_LOGIN=5               # login attempts per 15 minutes
RATE_LIMIT_PASSWORD_RESET=3      # password reset per hour
RATE_LIMIT_EXPORT=10             # exports per hour

# Burst Limits
RATE_LIMIT_BURST_DEV=50          # burst requests
RATE_LIMIT_BURST_CTL=100
RATE_LIMIT_BURST_PL=200
```

### Encryption Settings

```env
# Data Encryption
ENCRYPTION_KEY=base64-encoded-32-byte-key
ENCRYPTION_ALGORITHM=AES-256-GCM

# Password Hashing
PASSWORD_ALGORITHM=bcrypt
PASSWORD_COST=12                 # bcrypt rounds (higher = more secure/slower)

# Sensitive Data Fields (encrypted at rest)
ENCRYPT_FIELDS=oauth_tokens,api_keys,personal_notes

# Key Rotation
KEY_ROTATION_ENABLED=true
KEY_ROTATION_INTERVAL=90         # days
PREVIOUS_KEYS='["old-key-1","old-key-2"]'  # For data migration
```

---

## Performance Tuning

### PHP Configuration

```ini
# php.ini optimizations for TimeTracker
memory_limit = 512M
max_execution_time = 300         # For large exports
post_max_size = 50M
upload_max_filesize = 10M

# OPcache Settings
opcache.enable = 1
opcache.enable_cli = 0
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 10000
opcache.validate_timestamps = 0  # Production only
opcache.revalidate_freq = 0
opcache.save_comments = 0

# APCu (User Cache)
apc.enabled = 1
apc.shm_size = 128M
apc.ttl = 3600
```

### Symfony Framework Configuration

```yaml
# config/packages/framework.yaml
framework:
    cache:
        app: cache.adapter.apcu
        system: cache.adapter.system
        default_redis_provider: redis://redis:6379/0
        pools:
            cache.user_sessions:
                adapter: cache.adapter.redis
                provider: redis://redis:6379/1
            cache.ldap_queries:
                adapter: cache.adapter.apcu
                default_lifetime: 300
            cache.jira_metadata:
                adapter: cache.adapter.redis
                default_lifetime: 3600

    session:
        handler_id: redis://redis:6379/2
        cookie_lifetime: 1800
        gc_maxlifetime: 3600

    http_client:
        default_options:
            timeout: 30
            max_redirects: 3
        scoped_clients:
            jira.client:
                timeout: 60
                retry_failed: true
                max_retries: 3
```

### Database Optimization

```env
# Connection Pool Configuration
DATABASE_POOL_MIN_SIZE=5
DATABASE_POOL_MAX_SIZE=20
DATABASE_POOL_ACQUIRE_TIMEOUT=30
DATABASE_POOL_IDLE_TIMEOUT=600

# Query Optimization
DOCTRINE_CACHE_ENABLED=true
DOCTRINE_QUERY_CACHE_DRIVER=redis
DOCTRINE_RESULT_CACHE_DRIVER=redis
DOCTRINE_METADATA_CACHE_DRIVER=apcu

# Batch Processing
BULK_INSERT_BATCH_SIZE=100
EXPORT_CHUNK_SIZE=1000
REPORT_CACHE_TTL=3600
```

### Caching Strategy

```env
# Redis Configuration
REDIS_URL=redis://redis:6379
REDIS_CLUSTER_NODES=redis-1:6379,redis-2:6379,redis-3:6379

# Cache TTL Settings
CACHE_USER_DATA_TTL=900          # 15 minutes
CACHE_PROJECT_DATA_TTL=3600      # 1 hour
CACHE_LDAP_QUERIES_TTL=300       # 5 minutes
CACHE_REPORTS_TTL=1800           # 30 minutes
CACHE_EXPORTS_TTL=86400          # 24 hours

# Cache Warming
CACHE_WARMUP_ENABLED=true
CACHE_WARMUP_SCHEDULE="0 6 * * *"  # Daily at 6 AM
```

---

## Logging & Monitoring

### Structured Logging

```yaml
# config/packages/monolog.yaml
monolog:
    channels:
        - security
        - ldap
        - jira
        - api
        - performance
        
    handlers:
        main:
            type: rotating_file
            path: '%kernel.logs_dir%/app.log'
            level: info
            max_files: 30
            formatter: json
            
        security:
            type: rotating_file  
            path: '%kernel.logs_dir%/security.log'
            level: warning
            channels: [security]
            formatter: json
            
        performance:
            type: rotating_file
            path: '%kernel.logs_dir%/performance.log'
            level: info
            channels: [performance]
            
        syslog:
            type: syslog
            facility: local0
            level: error
```

### Environment-specific Logging

```env
# Development
LOG_LEVEL=debug
LOG_CHANNELS=app,security,ldap,jira
LOG_FORMAT=line                  # Human-readable format
PROFILER_ENABLED=true

# Production
LOG_LEVEL=warning
LOG_CHANNELS=security,error
LOG_FORMAT=json                  # Structured format for analysis
LOG_TO_STDOUT=false

# Centralized Logging
SYSLOG_ENABLED=true
SYSLOG_FACILITY=local0
ELK_ENABLED=true
ELK_INDEX=timetracker-logs
```

### Monitoring & Metrics

```env
# Application Metrics
METRICS_ENABLED=true
METRICS_ENDPOINT=/metrics
METRICS_TOKEN=monitoring-secret-token

# Prometheus Integration
PROMETHEUS_ENABLED=true
PROMETHEUS_PUSHGATEWAY=http://prometheus-gateway:9091
PROMETHEUS_JOB_NAME=timetracker

# Health Checks
HEALTH_CHECK_ENABLED=true
HEALTH_CHECK_ENDPOINT=/health
HEALTH_CHECKS=database,ldap,redis,filesystem

# Error Tracking (Sentry)
SENTRY_DSN=https://key@sentry.company.com/project
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=v4.1.0
SENTRY_TRACES_SAMPLE_RATE=0.1
```

---

## Multi-tenant Setup

### Tenant Configuration

```env
# Multi-tenant Mode
MULTI_TENANT_ENABLED=true
TENANT_STRATEGY=subdomain        # subdomain, path, header, database

# Subdomain Strategy
TENANT_DOMAIN=timetracker.company.com
TENANT_SUBDOMAINS=client1,client2,client3

# Database per Tenant
TENANT_DATABASE_PREFIX=tenant_
TENANT_DATABASE_HOST=tenant-db.company.com

# Shared Database with Schema Separation
TENANT_SCHEMA_PREFIX=client_
DEFAULT_TENANT=default
```

### Tenant-specific Settings

```yaml
# config/tenants/client1.yaml
tenant:
    name: "Client One Inc"
    domain: "client1.timetracker.company.com"
    database_url: "mysql://client1:password@db:3306/tenant_client1"
    
    ldap:
        host: "ldap.client1.com"
        base_dn: "dc=client1,dc=com"
        
    jira:
        base_url: "https://client1.atlassian.net"
        consumer_key: "timetracker-client1"
        
    branding:
        logo: "/assets/logos/client1.png"
        colors:
            primary: "#1e40af"
            secondary: "#64748b"
            
    features:
        jira_integration: true
        advanced_reports: true
        api_access: true
```

### Tenant Routing

```php
// src/EventListener/TenantListener.php
class TenantListener
{
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $host = $request->getHost();
        
        // Extract tenant from subdomain
        if (preg_match('/^([^.]+)\.timetracker\.company\.com$/', $host, $matches)) {
            $tenantId = $matches[1];
            $request->attributes->set('_tenant', $tenantId);
        }
    }
}
```

---

## Troubleshooting

### Configuration Validation

```bash
# Validate all configuration
php bin/console app:config:validate

# Check specific components
php bin/console app:config:check --component=ldap
php bin/console app:config:check --component=database
php bin/console app:config:check --component=jira

# Test connectivity
php bin/console app:connectivity:test
```

### Common Configuration Issues

#### 1. LDAP Connection Problems

```bash
# Problem: Can't contact LDAP server
# Check: Network connectivity and firewall
telnet ldap.company.com 389

# Check: LDAP server status
ldapsearch -x -H ldap://ldap.company.com -b "" -s base "(objectclass=*)"

# Check: Credentials and permissions
ldapsearch -x -H ldap://ldap.company.com -D "cn=readonly,dc=company,dc=com" -w password -b "dc=company,dc=com" "(uid=testuser)"
```

#### 2. Database Connection Issues

```bash
# Problem: Connection refused
# Check: Database server status
systemctl status mysql

# Check: Network connectivity
telnet database-server 3306

# Check: User permissions
mysql -h database-server -u timetracker -p
SHOW GRANTS FOR 'timetracker'@'%';
```

#### 3. JIRA OAuth Problems

```bash
# Problem: OAuth authentication failed
# Check: RSA key format and permissions
openssl rsa -in jira_private.pem -check -noout

# Check: Consumer key configuration in JIRA
# Verify in JIRA Application Links settings

# Test OAuth flow
php bin/console app:jira:test-oauth
```

#### 4. Performance Issues

```bash
# Problem: Slow response times
# Check: Database performance
mysql -e "SHOW PROCESSLIST;"
mysql -e "SHOW ENGINE INNODB STATUS\G"

# Check: PHP performance
php bin/console app:performance:profile

# Check: Cache status
php bin/console cache:pool:list
redis-cli info memory
```

### Debug Configuration

```env
# Enable debug mode for configuration
CONFIG_DEBUG=true
CONFIG_LOG_LEVEL=debug

# Dump effective configuration
php bin/console debug:config
php bin/console debug:container

# Validate Symfony configuration
php bin/console lint:yaml config/
php bin/console lint:container
```

---

## Configuration Templates

### Quick Start Templates

#### Small Organization (< 50 users)
```env
# Single server setup
APP_ENV=prod
DATABASE_URL="mysql://timetracker:password@localhost:3306/timetracker"
LDAP_HOST=ldap.company.com
JIRA_INTEGRATION_ENABLED=true
REDIS_URL=redis://localhost:6379
```

#### Medium Organization (50-200 users)
```env
# Load-balanced setup with caching
APP_ENV=prod
DATABASE_URL="mysql://timetracker:password@db-cluster:3306/timetracker"
LDAP_HOSTS=["ldap1.company.com","ldap2.company.com"]
JIRA_INTEGRATION_ENABLED=true
REDIS_URL=redis://redis-cluster:6379
RATE_LIMIT_ENABLED=true
```

#### Large Organization (200+ users)
```env
# High-availability multi-tenant setup
MULTI_TENANT_ENABLED=true
DATABASE_URL="mysql://timetracker:password@db-primary:3306/timetracker"
DATABASE_READ_URL="mysql://timetracker_ro:password@db-replica:3306/timetracker"
REDIS_CLUSTER_NODES=redis-1:6379,redis-2:6379,redis-3:6379
LDAP_POOL_SIZE=20
JIRA_BULK_SYNC_ENABLED=true
METRICS_ENABLED=true
```

---

**🎉 Configuration Complete!** 

Your TimeTracker instance should now be properly configured for your environment.

For additional help:
- 📚 [Developer Setup Guide](DEVELOPER_SETUP.md)
- 🔧 [API Usage Guide](API_USAGE_GUIDE.md)
- 🛡️ [Security Implementation](SECURITY_IMPLEMENTATION_GUIDE.md)

---

**Last Updated**: 2025-01-20  
**Configuration Version**: v4.1  
**Questions**: Create a GitHub issue or contact the development team