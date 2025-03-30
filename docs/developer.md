# Developer Guide

## Environment Setup

### Requirements
- Docker and Docker Compose
- VS Code with recommended extensions
- Git

### First-time Setup
1. Clone the repository:
   ```
   git clone [repository-url]
   cd timetracker
   ```

2. Start the development environment:
   ```
   docker compose up -d
   ```

3. Install dependencies:
   ```
   composer install
   ```

4. Create the database:
   ```
   bin/console doctrine:database:create
   bin/console doctrine:migrations:migrate
   ```

5. Install the recommended VS Code extensions (see .vscode/extensions.json)

### VS Code Extensions
The following extensions are recommended for this project:
- junstyle.php-cs-fixer - PHP code formatting
- bmewburn.vscode-intelephense-client - PHP language support
- mehedidracula.php-namespace-resolver - PHP namespace support
- neilbrayfield.php-docblocker - PHPDoc generation
- recca0120.vscode-phpunit - PHPUnit testing
- ms-azuretools.vscode-docker - Docker support
- eamodio.gitlens - Git integration
- editorconfig.editorconfig - Editor configuration
- mikestead.dotenv - .env file support
- ikappas.phpcs - PHP CodeSniffer
- devsense.composer-php-vscode - Composer integration

## Development Workflow

### Docker Command Pattern
All commands must be run inside Docker containers using the following pattern:

```
docker compose run --rm app <command>
```

For PHPUnit tests, also include the test environment:

```
docker compose run --rm -e APP_ENV=test app <command>
```

**Remember to prefix all commands below with the Docker pattern above.**

### Available Commands

**Composer**
- `composer install` - Install dependencies
- `composer update` - Update dependencies
- `composer require <package>` - Add a new dependency
- `composer remove <package>` - Remove a dependency

**Composer Scripts**
- `composer cs-check` - Run PHP_CodeSniffer
- `composer cs-fix` - Run PHP-CS-Fixer
- `composer analyze` - Run PHPStan
- `composer psalm` - Run Psalm
- `composer test` - Run PHPUnit
- `composer security-check` - Run Local PHP Security Checker

**Symfony Console**
- `bin/console <command>` - Run any Symfony command
- `bin/console doctrine:migrations:migrate` - Run database migrations
- `bin/console cache:clear` - Clear the cache
- `bin/console debug:router` - Show all routes

**PHPUnit**
- `bin/phpunit` - Run all tests
- `bin/phpunit --filter <TestName>` - Run specific tests

### Git Workflow
1. Create a feature branch:
   ```
   git checkout -b feature/your-feature-name
   ```

2. Make changes and commit regularly
3. Run tests and static analysis before pushing:
   ```
   composer test
   composer analyze
   composer cs-check
   ```

4. Push and create a pull request

## Coding Standards

This project follows:
- PSR-12 coding standards
- Static analysis with PHPStan (level 8) and Psalm
- Strict typing in all PHP files
- Type hints for all function parameters and return types

## Project Structure

### Directory Layout
- `src/` - Application source code
- `config/` - Configuration files
- `templates/` - Twig templates
- `public/` - Web root, publicly accessible files
- `bin/` - Executable files
- `migrations/` - Database migrations
- `tests/` - Test files
- `var/` - Generated files (cache, logs)
- `vendor/` - Dependencies
- `assets/` - Frontend assets

## Testing

### Running Tests
```
composer test
```

### Test Database
A separate database is used for testing. It is defined in the `compose.dev.yml` file.

### Browser Testing with Panther
This project uses Symfony Panther for browser testing. These tests simulate a real browser and allow testing JavaScript functionality.

To run browser tests:

```
bin/phpunit tests/Browser
```

Browser tests are configured to use a Selenium Chrome container that is part of the Docker setup. The tests connect to this container to run headless Chrome tests.

**Important:** Browser tests require the Chrome service to be running. Make sure your Docker environment is up and running with `docker compose up -d` before executing browser tests.

You can also view browser tests in real-time using VNC:
1. Start the Docker environment
2. Connect to VNC at `localhost:7900` (password: "secret")
3. Run the browser tests
4. Watch the tests execute in real-time

#### Creating Browser Tests

1. Create a new test class that extends `Tests\BrowserTestCase`
2. Use the `createCustomPantherClient()` method to get a browser client
3. Use Panther assertion methods to test elements on the page

Example:
```php
$client = self::createCustomPantherClient();
$crawler = $client->request('GET', '/');
self::assertSelectorExists('.some-element');
```

## Static Analysis

### PHPStan
```
composer analyze
```

### Psalm
```
composer psalm
```

## Security

Refer to `docs/security-checklist.md` for the security audit checklist used during code reviews.

## CI/CD

GitHub Actions workflows:
- `code-quality.yml` - Static analysis and coding standards
- `tests.yml` - Automated testing
- `docker-publish.yml` - Docker image publishing

## Troubleshooting

### Common Issues

1. **Docker errors**:
   ```
   docker compose down -v
   docker compose up -d
   ```

2. **Permissions issues**:
   ```
   sudo chown -R $(id -u):$(id -g) .
   ```

3. **Cache issues**:
   ```
   bin/console cache:clear
   ```

