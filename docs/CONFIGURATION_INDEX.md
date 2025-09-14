# TimeTracker Configuration Index

> Complete configuration reference for the TimeTracker application
> Last Updated: September 14, 2025

## 📁 Configuration Structure

```
config/
├── packages/           # Package-specific configurations
│   ├── dev/           # Development environment
│   ├── prod/          # Production environment
│   ├── test/          # Test environment
│   └── *.yaml         # Shared package configs
├── quality/           # Code quality tool configs
│   ├── phpstan.dist.neon
│   ├── phpstan.neon
│   ├── rector.php
│   ├── phpat.php
│   └── pint.json
├── testing/           # Test configurations
│   ├── paratest.xml
│   ├── phpunit-performance.xml
│   └── phpunit.xml.verbose
├── routes/            # Routing configuration
├── services.yaml      # Service definitions
└── bundles.php        # Bundle registration
```

## 🔧 Environment Configuration

### Environment Files

| File | Purpose | Git Status |
|------|---------|------------|
| `.env` | Default configuration | ✅ Committed |
| `.env.local` | Local overrides | ❌ Gitignored |
| `.env.dev` | Development settings | ✅ Committed |
| `.env.test` | Test environment | ✅ Committed |
| `.env.prod` | Production template | ❌ Not in repo |

### Key Environment Variables

```bash
# Application
APP_ENV=dev|test|prod           # Environment mode
APP_SECRET=                      # Symfony secret key
APP_DEBUG=1|0                    # Debug mode

# Database
DATABASE_URL=mysql://user:pass@db:3306/timetracker
DB_HOST=db
DB_PORT=3306
DB_DATABASE=timetracker
DB_USERNAME=root
DB_PASSWORD=root

# LDAP Configuration
LDAP_HOST=ldap.example.com
LDAP_PORT=389
LDAP_ENCRYPTION=none|ssl|tls
LDAP_BASE_DN=dc=example,dc=com
LDAP_SEARCH_DN=cn=admin,dc=example,dc=com
LDAP_SEARCH_PASSWORD=

# JIRA Integration (Optional)
JIRA_BASE_URL=https://jira.example.com
JIRA_CONSUMER_KEY=
JIRA_PRIVATE_KEY=
JIRA_OAUTH_CALLBACK_URL=

# Application Settings
DEFAULT_LANGUAGE=en
TIMEZONE=Europe/Berlin
ITEMS_PER_PAGE=20
```

## 🐳 Docker Configuration

### Docker Compose Services

```yaml
services:
  app:            # PHP-FPM application
    build: .
    environment:
      - APP_ENV=${APP_ENV:-dev}
    volumes:
      - .:/var/www/html

  nginx:          # Web server
    image: nginx:alpine
    ports:
      - "8765:80"
    volumes:
      - ./docker/nginx/:/etc/nginx/conf.d/

  db:             # MariaDB database
    image: mariadb:10.6
    environment:
      - MYSQL_ROOT_PASSWORD=root
      - MYSQL_DATABASE=timetracker
    ports:
      - "3306:3306"

  db_unittest:    # Test database
    image: mariadb:10.6
    environment:
      - MYSQL_ROOT_PASSWORD=unittest
      - MYSQL_DATABASE=unittest
```

### Docker Files

| File | Location | Purpose |
|------|----------|---------|
| Dockerfile | `/Dockerfile` | PHP application image |
| compose.yml | `/compose.yml` | Main services |
| compose.override.yml | `/compose.override.yml` | Local overrides |
| nginx configs | `/docker/nginx/` | Web server configs |
| LDAP configs | `/docker/ldap/` | LDAP test data |

## 📦 Package Configuration

### Symfony Packages

| Package | Config File | Purpose |
|---------|------------|---------|
| **framework** | `framework.yaml` | Core Symfony settings |
| **doctrine** | `doctrine.yaml` | Database ORM |
| **security** | `security.yaml` | Authentication/Authorization |
| **twig** | `twig.yaml` | Template engine |
| **validator** | `validator.yaml` | Input validation |
| **messenger** | `messenger.yaml` | Message bus |
| **mailer** | `mailer.yaml` | Email sending |
| **monolog** | `monolog.yaml` | Logging |
| **webpack_encore** | `webpack_encore.yaml` | Asset building |

### Key Configuration Settings

#### Security Configuration
```yaml
# config/packages/security.yaml
security:
    providers:
        app_user_provider:
            entity:
                class: App\Entity\User
                property: username

    firewalls:
        main:
            lazy: true
            provider: app_user_provider
            custom_authenticator: App\Security\LdapAuthenticator
            logout:
                path: app_logout
                csrf_token_manager: ~

    access_control:
        - { path: ^/admin, roles: ROLE_PL }
        - { path: ^/controlling, roles: ROLE_CTL }
        - { path: ^/, roles: ROLE_USER }
```

#### Database Configuration
```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        charset: utf8mb4
        server_version: '10.6'

    orm:
        auto_generate_proxy_classes: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        auto_mapping: true
        mappings:
            App:
                is_bundle: false
                dir: '%kernel.project_dir%/src/Entity'
                prefix: 'App\Entity'
                alias: App
```

## 🔨 Build Configuration

### Composer Scripts
```json
{
    "scripts": {
        "auto-scripts": {
            "cache:clear": "symfony-cmd",
            "assets:install %PUBLIC_DIR%": "symfony-cmd"
        },
        "test": "APP_ENV=test phpunit",
        "test:coverage": "APP_ENV=test phpunit --coverage-html var/coverage",
        "stan": "phpstan analyse --configuration=config/quality/phpstan.neon",
        "cs-check": "pint --test --config=config/quality/pint.json",
        "cs-fix": "pint --config=config/quality/pint.json"
    }
}
```

### Webpack Configuration
```javascript
// webpack.config.js
Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')
    .addEntry('app', './assets/app.js')
    .enableStimulusBridge('./assets/controllers.json')
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())
    .configureBabel((config) => {
        config.plugins.push('@babel/plugin-proposal-class-properties');
    })
    .enableSassLoader()
    .autoProvidejQuery()
;
```

## 🧪 Quality Tool Configuration

### PHPStan (Level 8)
```neon
# config/quality/phpstan.dist.neon
parameters:
    level: 8
    paths:
        - src
        - tests
    excludePaths:
        - src/Migrations
    ignoreErrors:
        - '#Dynamic call to static method#'
    treatPhpDocTypesAsCertain: false
```

### Rector (PHP Modernization)
```php
# config/quality/rector.php
return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/../../src',
    ]);

    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_84,
        SymfonySetList::SYMFONY_73,
        SymfonySetList::SYMFONY_CODE_QUALITY,
    ]);
};
```

### Laravel Pint (Code Style)
```json
// config/quality/pint.json
{
    "preset": "symfony",
    "rules": {
        "declare_strict_types": true,
        "final_class": true,
        "ordered_imports": {
            "sort_algorithm": "alpha"
        }
    }
}
```

## 🔄 Makefile Configuration

### Key Targets
```makefile
# Core Operations
up:              # Start development stack
down:            # Stop development stack
restart:         # Restart all services
build:           # Build Docker images

# Testing
test:            # Run test suite
test-parallel:   # Run tests in parallel
coverage:        # Generate coverage report

# Quality Checks
check-all:       # Run all quality checks
stan:            # PHPStan analysis
cs-check:        # Code style check
cs-fix:          # Fix code style

# Database
db-migrate:      # Run migrations
reset-test-db:   # Reset test database

# New Targets (Added Today)
validate-stack:  # Validate entire toolchain
analyze-coverage:# Analyze test coverage
```

## 🔐 Security Configuration

### CSRF Protection
- Stateless CSRF implementation
- Token validation on state-changing operations
- Automatic token generation for forms

### Authentication
- LDAP/AD integration
- Session-based authentication
- Remember-me functionality

### Authorization
- Role-based access control (RBAC)
- Three roles: DEV, CTL, PL
- Hierarchical permissions

## 📊 Performance Configuration

### Caching
```yaml
framework:
    cache:
        app: cache.adapter.filesystem
        pools:
            doctrine.query_cache_pool:
                adapter: cache.app
                default_lifetime: 3600
```

### Database Optimization
- Query result caching
- Lazy loading for associations
- Index optimization

## 🚀 Deployment Configuration

### Production Checklist
- [ ] Set `APP_ENV=prod`
- [ ] Set `APP_DEBUG=0`
- [ ] Configure real database credentials
- [ ] Set strong `APP_SECRET`
- [ ] Configure LDAP production settings
- [ ] Enable HTTPS
- [ ] Configure log rotation
- [ ] Set up monitoring
- [ ] Configure backup strategy

### Environment-Specific Settings

| Setting | Dev | Test | Prod |
|---------|-----|------|------|
| Debug | ✅ | ✅ | ❌ |
| Profiler | ✅ | ❌ | ❌ |
| Cache | ❌ | ❌ | ✅ |
| Error Display | ✅ | ✅ | ❌ |
| Log Level | DEBUG | INFO | WARNING |

---

*This configuration index provides a complete reference for all TimeTracker configuration files and settings. For specific configuration changes, always test in development before deploying to production.*