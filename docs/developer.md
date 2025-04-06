# Developer Guide

This guide provides instructions for setting up and contributing to the Netresearch TimeTracker application.

## Environment Setup

The development environment relies entirely on Docker and Docker Compose.

### Requirements
- Docker ([Install Guide](https://docs.docker.com/get-docker/))
- Docker Compose ([Install Guide](https://docs.docker.com/compose/install/))
- Git ([Install Guide](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git))
- VS Code (Recommended) with suggested extensions (see below)

### First-time Setup
1.  Clone the repository:
    ```bash
    git clone git@github.com:netresearch/timetracker.git
    cd timetracker
    ```

2.  Build and start the development environment containers:
    ```bash
    docker compose -f compose.yml -f compose.dev.yml up --build -d
    ```
    *   This uses `compose.yml` for base services and `compose.dev.yml` for development-specific overrides (like mounting source code).
    *   The `-d` flag runs the containers in detached mode (in the background).

3.  Install PHP dependencies using Composer (inside the `app` container):
    ```bash
    docker compose run --rm app composer install
    ```

4.  Set up the database. Create a `.env.local` file if you need to override the default database connection details found in `.env`. Then run migrations (inside the `app` container):
    ```bash
    # Optional: If the database doesn't exist yet
    # docker compose run --rm app bin/console doctrine:database:create

    # Apply migrations
    docker compose run --rm app bin/console doctrine:migrations:migrate --no-interaction
    ```
    *Note: The database service (`db`) defined in `compose.yml` uses the credentials from the `.env` file by default. The test database (`db_unittest`) is defined in `compose.dev.yml`.*

5.  Install Node.js dependencies and build frontend assets:
    ```bash
    docker compose run --rm app npm install
    docker compose run --rm app npm run build # Or 'npm run dev' for development build with watching
    ```

6.  Access the application:
    Open your browser and navigate to `http://localhost:8765` (or the port configured in `compose.dev.yml`).

### VS Code Extensions
The following extensions are recommended (`.vscode/extensions.json`):
- `bmewburn.vscode-intelephense-client` - PHP language support & IntelliSense
- `junstyle.php-cs-fixer` - PHP code formatting (PSR-12)
- `ikappas.phpcs` - PHP CodeSniffer integration
- `recca0120.vscode-phpunit` - PHPUnit testing integration
- `mehedidracula.php-namespace-resolver` - PHP namespace assistance
- `neilbrayfield.php-docblocker` - PHPDoc comment generation
- `ms-azuretools.vscode-docker` - Docker integration
- `eamodio.gitlens` - Enhanced Git capabilities
- `editorconfig.editorconfig` - EditorConfig for VS Code
- `mikestead.dotenv` - .env file syntax highlighting
- `redhat.vscode-yaml` - YAML language support
- `devsense.composer-php-vscode` - Composer integration

## Development Workflow

### Command Execution via Docker
**All** development commands (Composer, Symfony Console, PHPUnit, etc.) **must** be run inside the `app` Docker container using `docker compose run --rm app <command>`. This ensures commands execute with the correct PHP version, extensions, and environment configuration.

**Examples:**

*   **Composer:** `docker compose run --rm app composer update`
*   **Symfony Console:** `docker compose run --rm app bin/console cache:clear`
*   **PHPUnit (using test environment):** `docker compose run --rm -e APP_ENV=test app bin/phpunit`

### Available Commands & Tools

Refer to the `scripts` section in `composer.json` for shortcuts to common tasks. Run them using the Docker pattern, e.g., `docker compose run --rm app composer cs-check`.

*   **Dependency Management (Composer):**
    *   `composer install`: Install dependencies from `composer.lock`.
    *   `composer update`: Update dependencies to latest allowed versions and update `composer.lock`.
    *   `composer require <package>`: Add a new production dependency.
    *   `composer require --dev <package>`: Add a new development dependency.
    *   `composer remove <package>`: Remove a dependency.
*   **Code Quality & Static Analysis:**
    *   `composer cs-check`: Check code style against PSR-12 (PHP_CodeSniffer).
    *   `composer cs-fix`: Automatically fix code style issues (PHP-CS-Fixer).
    *   `composer analyze`: Run static analysis (PHPStan).
    *   `composer psalm`: Run static analysis (Psalm).
*   **Testing (PHPUnit):**
    *   `composer test`: Run the main PHPUnit test suite.
    *   `composer test:unit`: Run unit tests.
    *   `composer test:controller`: Run controller tests.
    *   `composer test:parallel`: Run unit tests in parallel (requires `paratest`).
    *   `composer test:coverage`: Run tests and generate an HTML coverage report in `var/coverage/`.
    *   `composer test:coverage-text`: Run tests and show coverage in the console.
    *   Run specific tests: `docker compose run --rm -e APP_ENV=test app bin/phpunit --filter <TestClassNameOrMethodName>`
*   **Symfony Console:** Access various framework commands.
    *   `bin/console list`: List all available commands.
    *   `bin/console debug:router`: Display configured routes.
    *   `bin/console debug:container`: Display configured services.
    *   `bin/console doctrine:migrations:diff`: Generate a new migration file based on entity changes.
    *   `bin/console make:...`: Generate boilerplate code (controllers, entities, etc.).
*   **Security:**
    *   `composer security-check`: Check dependencies for known vulnerabilities.

### Git Workflow
1.  Keep your `main` branch up-to-date.
2.  Create a feature branch from `main`:
    ```bash
    git checkout main
    git pull origin main
    git checkout -b feature/your-feature-name
    ```
3.  Make changes and commit frequently with clear messages.
4.  **Before pushing**, ensure your code passes quality checks:
    ```bash
    docker compose run --rm app composer cs-check
    docker compose run --rm app composer analyze
    docker compose run --rm -e APP_ENV=test app composer test
    ```
    *Optionally run `composer cs-fix` to fix style issues automatically.*
5.  Push your feature branch to the remote repository:
    ```bash
    git push origin feature/your-feature-name
    ```
6.  Create a Pull Request on GitHub for review.

## Coding Standards & Best Practices
Adhere to the following:
- **PHP 8.2:** Utilize modern PHP features.
- **Symfony 4.4:** Follow framework conventions and best practices.
- **PSR-12:** Enforced via PHP_CodeSniffer (`composer cs-check`) and PHP-CS-Fixer (`composer cs-fix`).
- **Strict Typing:** Use `declare(strict_types=1);` in all PHP files.
- **Type Hints:** Add scalar and return type hints wherever possible. Avoid `@param` and `@return` phpdoc annotations when type hints suffice.
- **Static Analysis:** Code should pass PHPStan (level 8) and Psalm checks (`composer analyze`, `composer psalm`).
- **Dependency Injection:** Utilize Symfony's autowiring and service container. Configure services in `config/services.yaml`.
- **Immutability:** Prefer immutable objects where practical.
- **Security:** Follow OWASP guidelines, use Symfony's security features (CSRF, validation), and sanitize all external input. Refer to `docs/security-checklist.md`.

## Project Structure

A standard Symfony 4.4 project structure is used. Key directories:
- `assets/`: Frontend assets (JS, CSS, images) processed by Webpack Encore.
- `bin/`: Executable files, including `console`.
- `config/`: Application configuration (bundles, routes, services, packages).
- `docs/`: Project documentation.
- `migrations/`: Doctrine database migration files.
- `public/`: Web server root; contains the front controller (`index.php`) and compiled assets.
- `src/`: PHP source code (Controllers, Entities, Repositories, Services, etc.).
- `templates/`: Twig template files.
- `tests/`: Automated tests (Unit, Integration, Controller).
- `translations/`: Translation files.
- `var/`: Temporary files (cache, logs). Not version controlled.
- `vendor/`: Composer dependencies. Not version controlled.

## Testing

See the "Available Commands & Tools" section above for how to run tests.

### Test Database
PHPUnit tests run against a separate database (`db_unittest` service in `compose.dev.yml`) configured via `.env.test`. Migrations are typically handled automatically within test setup, but ensure the schema (`sql/unittest/`) is appropriate.

### Browser Testing
*(If applicable - The current `developer.md` mentions Panther, but it's not in `composer.json`. Add this section if Panther is introduced)*

*(Placeholder for Panther setup/usage if added)*

## Database Migrations
Doctrine Migrations are used to manage database schema changes.
1.  Modify your Doctrine entities (`src/Entity/`).
2.  Generate a migration file:
    ```bash
    docker compose run --rm app bin/console doctrine:migrations:diff
    ```
3.  Review the generated migration file in `migrations/` for correctness.
4.  Apply the migration:
    ```bash
    docker compose run --rm app bin/console doctrine:migrations:migrate --no-interaction
    ```

## Frontend Development
Frontend assets are managed using Webpack Encore.
- Source files are located in `assets/`.
- Build assets using `npm run dev` (for development, includes watching) or `npm run build` (for production). These commands must be run inside the container:
    ```bash
    docker compose run --rm app npm run dev
    # or
    docker compose run --rm app npm run build
    ```
- Compiled assets are placed in `public/build/`.
- Include assets in Twig templates using Encore's `encore_entry_link_tags()` and `encore_entry_script_tags()` functions.

## Troubleshooting

### Common Issues

1.  **Docker Container Problems:** If containers are misbehaving:
    ```bash
    # Stop and remove containers, networks, and volumes
    docker compose down -v
    # Rebuild and start
    docker compose -f compose.yml -f compose.dev.yml up --build -d
    ```

2.  **Permissions Errors (especially in `var/`):** Docker might create files owned by root inside the container.
    *   Clear cache/logs: `docker compose run --rm app bin/console cache:clear` and `docker compose run --rm app rm -rf var/log/*`.
    *   If issues persist, you might need to adjust ownership *on the host* (Use with caution): `sudo chown -R $(id -u):$(id -g) var/`.

3.  **Database Connection Issues:**
    *   Verify `DATABASE_URL` in your `.env` or `.env.local` file matches the service name (`db`) and credentials in `compose.yml`.
    *   Ensure the database container is running (`docker compose ps`).

4.  **Outdated Dependencies:**
    *   `docker compose run --rm app composer update`
    *   `docker compose run --rm app npm update`

5.  **"White Screen" or Missing Assets:**
    *   Ensure frontend assets are built: `docker compose run --rm app npm run build`.
    *   Clear Symfony cache: `docker compose run --rm app bin/console cache:clear`.
    *   Check browser developer console for errors.
    *   Verify web server configuration (Nginx in `nginx-conf.d-default.conf`) correctly points to the `public/` directory.

