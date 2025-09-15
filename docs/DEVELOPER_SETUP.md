# Developer Setup Guide

**Complete development environment setup for TimeTracker application**

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Quick Setup (5 Minutes)](#quick-setup-5-minutes)
3. [Detailed Setup](#detailed-setup)
4. [IDE Configuration](#ide-configuration)
5. [Debugging Setup](#debugging-setup)
6. [Database & Fixtures](#database--fixtures)
7. [Git Workflow](#git-workflow)
8. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### System Requirements

| Component | Version | Purpose |
|-----------|---------|---------|
| **PHP** | 8.4+ | Application runtime |
| **Composer** | 2.5+ | Dependency management |
| **Node.js** | 18+ | Frontend asset compilation |
| **Docker** | 20.10+ | Container development (recommended) |
| **Git** | 2.30+ | Version control |

### Required PHP Extensions

```bash
# Check required extensions
php -m | grep -E "(ldap|pdo_mysql|openssl|intl|json|mbstring|opcache|apcu)"

# Install missing extensions (Ubuntu/Debian)
sudo apt install php8.4-ldap php8.4-mysql php8.4-intl php8.4-mbstring php8.4-opcache
```

### Optional Tools

- **Symfony CLI** - Enhanced development server
- **MySQL Workbench/TablePlus** - Database management
- **HTTPie/Insomnia** - API testing
- **Docker Desktop** - Container management GUI

---

## Quick Setup (5 Minutes)

```bash
# 1. Clone repository
git clone https://github.com/netresearch/timetracker.git
cd timetracker

# 2. Setup with Docker (recommended)
make up                    # Start all services
make install              # Install dependencies
make db-migrate           # Setup database

# 3. Load sample data
docker compose exec app php bin/console doctrine:fixtures:load --no-interaction

# 4. Verify setup
make test                 # Run tests
open http://localhost:8765

# Login with sample user: admin / admin
```

**🎉 You're ready to develop!**

---

## Detailed Setup

### 1. Environment Configuration

```bash
# Copy environment template
cp .env.example .env.local

# Generate secure keys
php -r "echo 'APP_SECRET=' . bin2hex(random_bytes(32)) . PHP_EOL;"
openssl rand -base64 32 | sed 's/^/APP_ENCRYPTION_KEY=/'
```

**Key Configuration Options:**

```env
# .env.local

# Application
APP_ENV=dev
APP_DEBUG=1
APP_SECRET=your-generated-secret-key
APP_ENCRYPTION_KEY=your-generated-encryption-key

# Database
DATABASE_URL="mysql://timetracker:timetracker@127.0.0.1:3306/timetracker?charset=utf8mb4"

# LDAP (for authentication)
LDAP_HOST=ldap.company.local
LDAP_PORT=389
LDAP_USESSL=false
LDAP_BASEDN="dc=company,dc=local"
LDAP_READUSER="cn=readonly,dc=company,dc=local"
LDAP_READPASS="readonly_password"
LDAP_USERNAMEFIELD=uid
LDAP_CREATE_USER=true

# Development
SYMFONY_ENV=dev
XDEBUG_MODE=debug
```

### 2. Database Setup

#### Option A: Docker Database (Recommended)
```bash
# Use Docker for development database
make up                   # Starts MySQL container
make db-migrate          # Run migrations
```

#### Option B: Local MySQL/MariaDB
```bash
# Create database
mysql -u root -p -e "CREATE DATABASE timetracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p -e "CREATE USER 'timetracker'@'localhost' IDENTIFIED BY 'timetracker';"
mysql -u root -p -e "GRANT ALL ON timetracker.* TO 'timetracker'@'localhost';"

# Import schema and run migrations
php bin/console doctrine:database:create --if-not-exists
php bin/console doctrine:migrations:migrate --no-interaction

# Apply performance optimizations
mysql timetracker < sql/full.sql
```

### 3. Dependencies Installation

```bash
# PHP dependencies
composer install --dev

# Frontend dependencies
npm install --legacy-peer-deps

# Build frontend assets
npm run dev              # Development build
npm run watch           # Watch mode for development
```

### 4. Verification

```bash
# Check system health
php bin/console about

# Verify database connection
php bin/console doctrine:schema:validate

# Run initial tests
composer test:fast

# Check code quality
composer check-all
```

---

## IDE Configuration

### PHPStorm Setup

1. **Create New Project**
   - Open PHPStorm → File → Open → Select `timetracker` directory
   - Configure PHP interpreter: Settings → PHP → CLI Interpreter → Add Docker/Local PHP 8.4

2. **Configure Code Style**
   ```xml
   <!-- .idea/codeStyles/Project.xml -->
   <code_scheme name="TimeTracker">
     <PHPCodeStyleSettings>
       <option name="PHPDOC_BLANK_LINE_BEFORE_TAGS" value="true" />
       <option name="PHPDOC_WRAP_LONG_LINES" value="true" />
       <option name="LOWER_CASE_BOOLEAN_CONST" value="true" />
       <option name="LOWER_CASE_NULL_CONST" value="true" />
     </PHPCodeStyleSettings>
   </code_scheme>
   ```

3. **Enable Inspections**
   - File → Settings → Editor → Inspections
   - Enable: PHP → Type compatibility, Undefined symbols, Doctrine

4. **Database Configuration**
   - View → Tool Windows → Database
   - Add MySQL data source: `localhost:3306/timetracker`
   - User: `timetracker`, Password: `timetracker`

### VS Code Setup

Create `.vscode/settings.json`:
```json
{
  "php.validate.executablePath": "/usr/bin/php8.4",
  "php.suggest.basic": false,
  "phpcs.enable": true,
  "phpcs.standard": "PSR12",
  "phpstan.enabled": true,
  "phpstan.configFile": "./phpstan.neon",
  "files.associations": {
    "*.twig": "twig"
  },
  "emmet.includeLanguages": {
    "twig": "html"
  }
}
```

**Recommended Extensions:**
- PHP IntelliSense
- PHP DocBlocker
- Twig Language 2
- Docker
- GitLens

### VI/Vim Configuration

Add to `.vimrc`:
```vim
" PHP settings
autocmd FileType php set omnifunc=phpcomplete#CompletePHP
autocmd FileType php set dictionary+=/path/to/symfony.dict

" Symfony-specific settings
set path+=/var/www/html/src
set path+=/var/www/html/config
set suffixesadd+=.php,.twig,.yml,.yaml
```

---

## Debugging Setup

### Xdebug Configuration

1. **Install Xdebug**
   ```bash
   # For Docker (already included)
   # For local development
   sudo apt install php8.4-xdebug
   ```

2. **Configure Xdebug**
   
   Create `/etc/php/8.4/mods-available/xdebug.ini`:
   ```ini
   zend_extension=xdebug.so
   xdebug.mode=debug
   xdebug.start_with_request=yes
   xdebug.client_host=localhost
   xdebug.client_port=9003
   xdebug.log=/tmp/xdebug.log
   xdebug.discover_client_host=1
   xdebug.idekey=PHPSTORM
   ```

3. **IDE Debugging Setup**

   **PHPStorm:**
   - File → Settings → PHP → Debug → Xdebug → Port: 9003
   - Run → Edit Configurations → Add → PHP Web Page
   - Name: `TimeTracker Debug`, Server: `localhost:8765`

   **VS Code:**
   Install PHP Debug extension and create `.vscode/launch.json`:
   ```json
   {
     "version": "0.2.0",
     "configurations": [
       {
         "name": "Listen for Xdebug",
         "type": "php",
         "request": "launch",
         "port": 9003,
         "pathMappings": {
           "/var/www/html": "${workspaceFolder}"
         }
       }
     ]
   }
   ```

### Debugging Workflow

```bash
# Start debugging session
export XDEBUG_MODE=debug
php -dxdebug.start_with_request=yes bin/console debug:router

# Debug specific test
./vendor/bin/phpunit --filter testCreateEntry tests/Controller/EntryControllerTest.php

# Debug with Docker
docker compose exec -e XDEBUG_MODE=debug app php bin/console cache:clear
```

### Performance Profiling

```bash
# Generate profiling data
export XDEBUG_MODE=profile
php bin/console app:export-large-dataset

# Analyze with tools like KCacheGrind
kcachegrind /tmp/cachegrind.out.*
```

---

## Database & Fixtures

### Database Management

```bash
# Database operations
make db-migrate           # Apply migrations
make db-reset            # Reset to clean state
make db-fixtures         # Load sample data

# Manual operations  
php bin/console doctrine:database:drop --force
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate --no-interaction
```

### Sample Data

The application includes comprehensive fixtures for development:

```bash
# Load all fixtures
php bin/console doctrine:fixtures:load --no-interaction

# Load specific fixture groups
php bin/console doctrine:fixtures:load --group=users,projects --no-interaction

# Available fixture groups:
# - users: Sample users with different roles
# - customers: Test customers and projects  
# - entries: Time entries for testing
# - teams: Team structures
```

**Sample Users Created:**
- **admin/admin** - Project Leader (full access)
- **controller/controller** - Controller (reporting access)
- **developer/developer** - Developer (basic access)

### Database Schema

**Key Tables:**
- `users` - User accounts and authentication
- `entries` - Time tracking entries
- `projects` - Project definitions
- `customers` - Customer information
- `teams` - Team organization
- `activities` - Activity types

**Performance Indexes:**
```sql
-- Key performance indexes
CREATE INDEX idx_entries_user_date ON entries (user_id, day);
CREATE INDEX idx_entries_project_date ON entries (project_id, day);  
CREATE INDEX idx_entries_ticket ON entries (ticket);
```

---

## Git Workflow

### Branch Strategy

```bash
# Main branches
main        # Production-ready code
develop     # Integration branch for features

# Feature branches
feature/PROJ-123-new-feature
hotfix/PROJ-456-critical-bug
release/v4.1.0
```

### Commit Standards

Follow [Conventional Commits](https://conventionalcommits.org/):

```bash
# Commit types
feat: add new time entry validation
fix: resolve duplicate entry creation bug
docs: update API documentation
test: add integration tests for auth
refactor: extract service for ticket validation
perf: optimize database queries for exports
chore: update dependencies
```

### Development Workflow

```bash
# 1. Create feature branch
git checkout -b feature/PROJ-123-description

# 2. Make changes and commit frequently
git add .
git commit -m "feat: add basic time entry form"

# 3. Run quality checks
make check-all              # Static analysis, code style
make test                   # Run test suite

# 4. Push and create PR
git push origin feature/PROJ-123-description
gh pr create --title "feat: New time entry validation" --body "Implements validation rules for overlapping entries"

# 5. After approval, merge via GitHub
```

### Pre-commit Hooks

Install pre-commit hooks to ensure code quality:

```bash
# Install hooks
composer install
npm install

# Husky will auto-install git hooks
# Manual installation:
cp .husky/pre-commit .git/hooks/
chmod +x .git/hooks/pre-commit
```

**Pre-commit Checks:**
- Code style (Laravel Pint)
- Static analysis (PHPStan)
- Test execution
- Twig template validation

---

## Troubleshooting

### Common Issues

#### 1. PHP Version Mismatch
```
Error: Your PHP version (8.1.x) is not supported. Required: 8.4+
```
**Solutions:**
```bash
# Ubuntu/Debian
sudo add-apt-repository ppa:ondrej/php
sudo apt update && sudo apt install php8.4-cli php8.4-fpm

# macOS with Homebrew
brew install php@8.4
brew link php@8.4
```

#### 2. Memory Exhausted During Tests
```
Fatal error: Allowed memory size of 512M exhausted
```
**Solutions:**
```bash
# Temporary increase
php -d memory_limit=2G ./vendor/bin/phpunit

# Permanent fix in php.ini
memory_limit = 2G

# For specific tests
export PHP_INI_SCAN_DIR=config/php/
make test
```

#### 3. LDAP Connection Issues
```
Error: Can't contact LDAP server (ldap://ldap.company.local)
```
**Solutions:**
```bash
# Test LDAP connectivity
ldapsearch -x -H ldap://ldap.company.local -b "dc=company,dc=local"

# Check firewall/network
telnet ldap.company.local 389

# Use test LDAP server for development
docker compose up ldap-dev
```

#### 4. Database Connection Failed
```
Connection refused [tcp://127.0.0.1:3306]
```
**Solutions:**
```bash
# Check database container status
docker compose ps db

# Restart database service
docker compose restart db

# Check database logs
docker compose logs db

# Test connection manually
mysql -h 127.0.0.1 -P 3306 -u timetracker -p
```

#### 5. Asset Build Failures
```
Module not found: Error: Can't resolve 'sass-loader'
```
**Solutions:**
```bash
# Clear npm cache
npm cache clean --force
rm -rf node_modules package-lock.json
npm install --legacy-peer-deps

# Use exact Node.js version
nvm use 18.17.0
npm install
```

### Performance Issues

#### Slow Test Execution
```bash
# Use parallel test execution
make test-parallel

# Run only specific test suites
composer test:unit              # Fast unit tests only
composer test:controller        # API endpoint tests

# Skip slow integration tests during development
./vendor/bin/phpunit --exclude-group=slow
```

#### High Memory Usage During Export
```bash
# Use streaming export for large datasets
php bin/console app:export:stream --format=xlsx --memory-limit=512M

# Monitor memory usage
php -d xdebug.mode=profile bin/console app:export
```

### Getting Help

1. **Check Existing Documentation**
   - [API Documentation](API_DOCUMENTATION.md)
   - [Architecture Guide](PROJECT_INDEX.md)
   - [Security Guide](SECURITY_IMPLEMENTATION_GUIDE.md)

2. **Search Issues**
   - [GitHub Issues](https://github.com/netresearch/timetracker/issues)
   - Common solutions in closed issues

3. **Community Support**
   - Internal Slack: `#timetracker-dev`
   - GitHub Discussions for questions

4. **Debug Information**
   ```bash
   # Gather system information
   php bin/console about
   php bin/console debug:config
   composer diagnose
   docker compose config
   ```

---

## Development Commands Reference

| Task | Docker | Manual |
|------|--------|--------|
| **Start Environment** | `make up` | `symfony server:start` |
| **Install Dependencies** | `make install` | `composer install && npm install` |
| **Database Setup** | `make db-migrate` | `php bin/console doctrine:migrations:migrate` |
| **Run Tests** | `make test` | `composer test` |
| **Code Quality** | `make check-all` | `composer check-all` |
| **Build Assets** | `make npm-build` | `npm run build` |
| **Generate Code** | `docker compose exec app php bin/console make:controller` | `php bin/console make:controller` |

---

**🎉 Congratulations!** Your development environment is now ready. Start by exploring the [API Documentation](API_DOCUMENTATION.md) or pick up a GitHub issue labeled `good-first-issue`.

---

**Last Updated**: 2025-01-20  
**Maintainer**: Development Team  
**Questions**: Create an issue or ask in `#timetracker-dev`