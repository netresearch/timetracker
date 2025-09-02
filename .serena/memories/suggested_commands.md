# Suggested Commands for TimeTracker Development

## Docker/Development Environment
```bash
# Start the development stack
docker compose up -d --build
make up

# Stop the stack
docker compose down
make down

# Access app container shell
docker compose exec app bash
make sh

# View logs
docker compose logs -f
make logs

# Full installation (composer + npm)
make install
```

## Testing Commands
```bash
# Run full test suite
make test
docker compose exec -T app sh -c 'APP_ENV=test php bin/phpunit'

# Run tests in parallel (faster)
make test-parallel

# Run specific test suites
make test  # All tests
composer test:unit  # Unit tests only
composer test:controller  # Controller tests only
composer test:fast  # Quick test run

# Test with coverage
make coverage
composer test:coverage  # HTML coverage
composer test:coverage-text  # Text coverage
```

## Code Quality & Static Analysis
```bash
# Run all checks (recommended before commit)
make check-all
composer check:all

# Individual checks
make stan  # PHPStan analysis (level 8)
make psalm  # Psalm analysis
make cs-check  # Check coding standards
make twig-lint  # Lint Twig templates

# Auto-fix issues
make fix-all
make cs-fix  # Fix coding standards
composer psalm:fix  # Auto-fix Psalm issues
composer rector  # Apply Rector rules
```

## Database & Cache
```bash
# Run database migrations
make db-migrate
docker compose exec app bin/console doctrine:migrations:migrate

# Clear cache
make cache-clear
docker compose exec app bin/console cache:clear

# Clear test environment cache
docker compose exec -T app sh -c 'APP_ENV=test php bin/console cache:clear'
```

## Dependency Management
```bash
# PHP dependencies
make composer-install
make composer-update

# JavaScript dependencies
make npm-install
make npm-build  # Production build
make npm-dev  # Development build
make npm-watch  # Watch for changes
```

## Git Workflow
```bash
# Check status before starting work
git status
git branch

# Create feature branch
git checkout -b feature/your-feature-name

# Before committing, run checks
make check-all
make test

# Commit with conventional message
git add .
git commit -m "type: description"

# Push to remote
git push origin feature/your-feature-name
```

## System Utilities (Linux)
```bash
# File operations
ls -la  # List files with details
find . -name "*.php"  # Find PHP files
grep -r "pattern" src/  # Search in source
rg "pattern"  # Faster search with ripgrep

# Process management
ps aux | grep php
docker ps  # List containers
docker compose ps  # List project containers

# Permissions
chmod +x script.sh
chown user:group file
```

## API/Development Tools
```bash
# Symfony console
docker compose exec app bin/console

# List routes
docker compose exec app bin/console debug:router

# Check services
docker compose exec app bin/console debug:container

# Swagger documentation
# Access at: http://localhost:8765/docs/swagger/index.html
```

## Quick Development Workflow
```bash
# 1. Start your work session
make up
git checkout -b feature/new-feature

# 2. Make changes, then validate
make check-all
make test

# 3. Fix any issues
make fix-all

# 4. Commit and push
git add .
git commit -m "feat: add new feature"
git push origin feature/new-feature
```