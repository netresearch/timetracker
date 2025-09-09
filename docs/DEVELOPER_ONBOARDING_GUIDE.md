# Developer Onboarding Guide

**Welcome to the TimeTracker Development Team!** 🚀

This guide will help you get up and running with the TimeTracker application development environment.

## Table of Contents

1. [Quick Start](#quick-start)
2. [System Requirements](#system-requirements)
3. [Environment Setup](#environment-setup)
4. [Project Structure](#project-structure)
5. [Development Workflow](#development-workflow)
6. [Common Tasks](#common-tasks)
7. [Testing](#testing)
8. [Code Standards](#code-standards)
9. [Troubleshooting](#troubleshooting)
10. [Resources](#resources)

## Quick Start

Get the application running in 5 minutes:

```bash
# Clone the repository
git clone https://github.com/company/timetracker.git
cd timetracker

# Install dependencies
composer install
npm install

# Copy environment configuration
cp .env.example .env.local
# Edit .env.local with your database and LDAP settings

# Set up the database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load  # Optional: Load test data

# Start the development server
symfony server:start
# Or use: php -S localhost:8000 -t public/

# Run tests to verify setup
composer test
```

Visit http://localhost:8000 to see the application running!

## System Requirements

### Required Software

- **PHP 8.4+** with extensions:
  - `ext-ldap` - LDAP authentication
  - `ext-pdo_mysql` - Database connectivity
  - `ext-openssl` - Token encryption
  - `ext-intl` - Internationalization
  - `ext-json` - JSON processing
  - `ext-mbstring` - Multi-byte string support

- **MySQL 8.0+** or **MariaDB 10.5+**
- **Composer 2.5+** - PHP dependency management
- **Node.js 18+** and **npm 9+** - Frontend assets
- **Git 2.30+** - Version control

### Recommended Tools

- **Symfony CLI** - Development server and tools
- **PHPStorm** or **VS Code** with PHP extensions
- **Docker Desktop** - Optional containerized development
- **TablePlus** or **DBeaver** - Database management

## Environment Setup

### 1. Database Configuration

```env
# .env.local
DATABASE_URL="mysql://root:password@127.0.0.1:3306/timetracker?charset=utf8mb4"
```

Create the database schema:
```bash
# Create database and run migrations
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Apply performance indexes (important!)
mysql timetracker < sql/full.sql
```

### 2. LDAP Configuration

```env
# .env.local
LDAP_HOST=ldap.company.internal
LDAP_PORT=389
LDAP_USESSL=false
LDAP_BASEDN="dc=company,dc=internal"
LDAP_READUSER="cn=reader,dc=company,dc=internal"
LDAP_READPASS="reader_password"
LDAP_USERNAMEFIELD=uid
LDAP_CREATE_USER=true
```

### 3. Security Configuration

```env
# .env.local
APP_SECRET=your-secret-key-min-32-chars
APP_ENCRYPTION_KEY=your-encryption-key-for-tokens
```

Generate secure keys:
```bash
# Generate APP_SECRET
php -r "echo bin2hex(random_bytes(32));"

# Generate APP_ENCRYPTION_KEY
openssl rand -base64 32
```

### 4. Development Tools Setup

```bash
# Install development dependencies
composer install --dev

# Install code quality tools
composer require --dev phpstan/phpstan
composer require --dev friendsofphp/php-cs-fixer
composer require --dev psalm/plugin-symfony

# Install frontend dependencies
npm install
npm run dev  # Build assets for development
```

## Project Structure

```
timetracker/
├── bin/                    # Console commands
│   └── console            # Symfony console entry point
├── config/                # Application configuration
│   ├── packages/          # Package-specific configs
│   │   ├── security.yaml  # Security configuration ⚠️
│   │   └── doctrine.yaml  # Database configuration
│   └── services.yaml      # Service definitions
├── docs/                  # Documentation
│   ├── adrs/             # Architecture Decision Records
│   └── API_DOCUMENTATION.md
├── migrations/           # Database migrations
├── public/              # Web root
│   └── index.php       # Application entry point
├── src/                # Source code
│   ├── Controller/     # HTTP controllers
│   │   ├── Admin/     # Admin endpoints
│   │   ├── Controlling/ # Reporting endpoints
│   │   └── Tracking/   # Time entry endpoints
│   ├── Dto/           # Data Transfer Objects
│   ├── Entity/        # Doctrine entities
│   ├── Enum/          # PHP 8.4 enums
│   ├── Repository/    # Data access layer
│   ├── Security/      # Authentication/authorization
│   └── Service/       # Business logic
├── tests/             # Test suite
│   ├── Controller/    # Controller tests
│   ├── Performance/   # Performance benchmarks
│   └── Service/       # Service tests
├── var/              # Generated files (cache, logs)
└── vendor/           # Composer dependencies
```

### Key Directories Explained

- **src/Controller/**: Action-based controllers (single responsibility)
- **src/Service/**: Business logic and integrations
- **src/Repository/**: Database queries and data access
- **src/Security/**: LDAP authenticator and token encryption
- **src/Dto/**: Request/response data structures with validation

## Development Workflow

### 1. Creating a New Feature

```bash
# Create a feature branch
git checkout -b feature/your-feature-name

# Make your changes
# ... edit files ...

# Run quality checks
composer cs-fix     # Fix code style
composer analyse    # Run static analysis
composer test       # Run tests

# Commit with conventional commits
git add .
git commit -m "feat: add new time entry validation"

# Push and create PR
git push origin feature/your-feature-name
```

### 2. Code Style and Quality

The project enforces strict code quality standards:

```bash
# Run all quality checks
composer quality

# Individual checks
composer cs-check   # Check code style
composer cs-fix     # Fix code style
composer analyse    # PHPStan static analysis
composer psalm      # Psalm type checking
```

### 3. Database Changes

```bash
# Create a new migration
php bin/console make:migration

# Review the generated migration
# Edit migrations/VersionXXXX.php if needed

# Apply migrations
php bin/console doctrine:migrations:migrate

# Rollback if needed
php bin/console doctrine:migrations:migrate prev
```

## Common Tasks

### Adding a New API Endpoint

1. **Create the Controller Action**:
```php
// src/Controller/Tracking/NewFeatureAction.php
namespace App\Controller\Tracking;

use App\Controller\BaseController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewFeatureAction extends BaseController
{
    #[Route('/tracking/new-feature', name: 'tracking_new_feature', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        // Implementation
    }
}
```

2. **Create a DTO for Validation**:
```php
// src/Dto/NewFeatureDto.php
namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class NewFeatureDto
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    public ?string $field = null;
}
```

3. **Add Service Logic**:
```php
// src/Service/NewFeatureService.php
namespace App\Service;

class NewFeatureService
{
    public function process(NewFeatureDto $dto): void
    {
        // Business logic
    }
}
```

4. **Write Tests**:
```php
// tests/Controller/NewFeatureActionTest.php
class NewFeatureActionTest extends WebTestCase
{
    public function testNewFeatureSuccess(): void
    {
        $client = static::createClient();
        $client->request('POST', '/tracking/new-feature', [
            'field' => 'value'
        ]);
        
        $this->assertResponseIsSuccessful();
    }
}
```

### Working with the Database

```bash
# Access database console
php bin/console doctrine:query:sql "SELECT * FROM entries LIMIT 10"

# Generate entity from database
php bin/console doctrine:mapping:import "App\Entity" annotation --path=src/Entity

# Validate schema
php bin/console doctrine:schema:validate
```

### Debugging

```php
// Use Symfony debug tools
dump($variable);  // Debug output
dd($variable);    // Dump and die

// Check service configuration
php bin/console debug:container EntryRepository

// Check routes
php bin/console debug:router

// Clear cache
php bin/console cache:clear
```

## Testing

### Running Tests

```bash
# Run all tests
composer test

# Run specific test suites
./vendor/bin/phpunit tests/Controller
./vendor/bin/phpunit tests/Service

# Run with coverage
composer test:coverage

# Run performance benchmarks
php tests/Performance/PerformanceBenchmarkRunner.php
```

### Writing Tests

Follow the test pyramid:
- **Unit Tests**: Test individual methods/classes
- **Integration Tests**: Test service interactions
- **Functional Tests**: Test API endpoints

Example test structure:
```php
class EntryServiceTest extends TestCase
{
    private EntryService $service;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EntryService(/* mocked dependencies */);
    }
    
    public function testCalculateDuration(): void
    {
        $result = $this->service->calculateDuration('09:00', '17:00');
        $this->assertEquals(480, $result);
    }
}
```

### Test Coverage Goals

Current: **38%** → Target: **80%**

Priority areas for test coverage:
1. Controllers (currently ~45%)
2. Services (currently ~60%)
3. Repositories (currently ~35%)
4. Security components (critical)

## Code Standards

### PHP Standards

- **PSR-12**: Code style standard
- **Strict Types**: Required in all PHP files
- **Type Hints**: Use everywhere possible
- **Final Classes**: Prefer final for non-extendable classes

```php
<?php

declare(strict_types=1);

namespace App\Service;

final class ExampleService
{
    public function process(string $input): array
    {
        // Implementation
    }
}
```

### Naming Conventions

- **Classes**: PascalCase (`UserService`)
- **Methods**: camelCase (`getUserById`)
- **Variables**: camelCase (`$userId`)
- **Constants**: UPPER_SNAKE_CASE (`MAX_RETRIES`)
- **Database**: snake_case (`user_id`)

### Git Commit Messages

Follow conventional commits:
```
feat: add user authentication
fix: resolve N+1 query in export
docs: update API documentation
test: add integration tests for auth
refactor: extract validation service
perf: optimize database queries
```

## Troubleshooting

### Common Issues

#### PHP Version Mismatch
```
Error: Composer dependencies require PHP >= 8.4.0
```
**Solution**: Install PHP 8.4 or use Docker development environment

#### LDAP Connection Failed
```
Error: Can't contact LDAP server
```
**Solution**: Check LDAP settings in `.env.local`, verify network connectivity

#### Database Migration Failed
```
Error: Table already exists
```
**Solution**: Reset database or check migration history:
```bash
php bin/console doctrine:migrations:status
php bin/console doctrine:migrations:sync-metadata-storage
```

#### Memory Exhausted During Export
```
Fatal error: Allowed memory size exhausted
```
**Solution**: Increase memory limit or use batched export:
```php
ini_set('memory_limit', '256M');
// Or use: $service->exportEntriesBatched()
```

### Getting Help

1. Check existing documentation in `/docs`
2. Search closed issues on GitHub
3. Ask in the team Slack channel
4. Contact the tech lead

## Resources

### Internal Documentation

- [API Documentation](./API_DOCUMENTATION.md) - Complete API reference
- [Security Guide](./SECURITY_IMPLEMENTATION_GUIDE.md) - Security patterns
- [Architecture Decision Records](./adrs/) - Key decisions
- [Breaking Changes](./BREAKING-CHANGES.md) - Migration guides

### External Resources

- [Symfony Documentation](https://symfony.com/doc/7.3/)
- [Doctrine ORM](https://www.doctrine-project.org/projects/orm.html)
- [PHP-FIG Standards](https://www.php-fig.org/psr/)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)

### Development Tools

- [Symfony Profiler](http://localhost:8000/_profiler) - Performance analysis
- [Doctrine Profiler](http://localhost:8000/_profiler/db) - Query analysis
- [API Platform](https://api-platform.com/) - Future API development

### Team Contacts

- **Tech Lead**: tech.lead@company.com
- **Security Team**: security@company.com
- **DevOps**: devops@company.com
- **Slack Channel**: #timetracker-dev

## Next Steps

1. ✅ Complete environment setup
2. ✅ Run the application locally
3. ✅ Run the test suite
4. 📖 Read the [Architecture Decision Records](./adrs/)
5. 🔒 Review the [Security Guide](./SECURITY_IMPLEMENTATION_GUIDE.md)
6. 🚀 Pick up a "good first issue" from GitHub
7. 💬 Introduce yourself in Slack!

Welcome aboard! We're excited to have you on the team. Don't hesitate to ask questions - we're here to help you succeed! 🎉

---

**Last Updated**: 2025-01-20  
**Maintainer**: Development Team  
**Version**: 1.0