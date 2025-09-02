# TimeTracker Project Structure

## Root Directory Layout
```
/home/cybot/projects/timetracker/
├── assets/            # Frontend assets (JS, CSS)
├── bin/              # Symfony console and binaries
├── claudedocs/       # Claude-specific documentation
├── config/           # Application configuration
│   ├── packages/     # Package-specific configs
│   ├── routes/       # Routing configuration
│   └── services.yaml # Service definitions
├── docs/             # Project documentation
├── migrations/       # Database migrations
├── public/           # Web root (index.php)
├── sql/              # SQL scripts and dumps
├── src/              # Application source code
│   ├── Controller/   # HTTP controllers
│   ├── Dto/         # Data Transfer Objects
│   ├── Entity/      # Doctrine entities
│   ├── Event/       # Event classes
│   ├── EventSubscriber/ # Event listeners
│   ├── Exception/   # Custom exceptions
│   ├── Model/       # Domain models
│   ├── Repository/  # Data repositories
│   ├── Security/    # Security components
│   ├── Service/     # Business services
│   └── Util/        # Utility classes
├── templates/        # Twig templates
├── tests/           # Test suites
├── translations/    # i18n translations
└── var/             # Cache, logs, temp files

## Key Configuration Files
- `composer.json` - PHP dependencies and scripts
- `package.json` - JavaScript dependencies
- `phpunit.xml.dist` - Test configuration
- `phpstan.dist.neon` - PHPStan config (level 8)
- `psalm.xml` - Psalm static analysis
- `docker-compose.yml` - Docker services
- `Makefile` - Common commands
- `.editorconfig` - Editor settings
- `.env` - Environment variables

## Controller Organization
```
src/Controller/
├── Admin/           # Administration endpoints
│   ├── SaveUserAction.php
│   ├── SaveProjectAction.php
│   └── ...
├── Tracking/        # Time tracking endpoints
│   ├── SaveEntryAction.php
│   ├── DeleteEntryAction.php
│   └── ...
├── Interpretation/  # Reporting endpoints
├── Controlling/     # Export/control endpoints
└── Default/         # General endpoints
```

## Service Layer Structure
```
src/Service/
├── Validation/      # Input validation
│   ├── ValidationService.php
│   ├── ValidationResult.php
│   └── ValidationException.php
├── Integration/     # External integrations
│   └── Jira/       # Jira API integration
├── Response/        # Response handling
│   └── ResponseFactory.php
├── Ldap/           # LDAP/AD authentication
└── Util/           # Utilities
```

## Entity Relationships
- Entry (timesheet entries)
- User (system users)
- Customer (clients)
- Project (customer projects)
- Activity (work types)
- Team (user groups)
- Contract (work contracts)
- Preset (bulk entry templates)

## Test Organization
```
tests/
├── Controller/      # Controller/integration tests
├── Entity/         # Entity/database tests
├── Service/        # Service unit tests
├── Dto/           # DTO validation tests
└── Util/          # Utility tests
```

## Docker Services
- `app` - PHP-FPM application
- `db` - MariaDB database
- `nginx` - Web server proxy
- Development on port 8765

## Important Patterns
1. **Single Action Controllers**: Each controller has one `__invoke` method
2. **DTO Validation**: DTOs handle request data and validation
3. **Repository Pattern**: Data access through repositories
4. **Service Layer**: Business logic in services
5. **Event System**: Event-driven architecture for cross-cutting concerns
6. **Final Classes**: Most classes marked as `final`
7. **Dependency Injection**: Constructor injection throughout

## Entry Points
- Web: `public/index.php`
- Console: `bin/console`
- Tests: `bin/phpunit`

## Build Artifacts
- `var/cache/` - Application cache
- `var/log/` - Application logs
- `node_modules/` - npm packages
- `vendor/` - Composer packages
- `public/build/` - Compiled assets