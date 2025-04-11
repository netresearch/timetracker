# Development Environment Setup

This guide describes how to set up the development environment for this project.

## Prerequisites

*   **Docker:** [Install Docker Desktop](https://www.docker.com/products/docker-desktop/) (includes Docker Compose).
*   **Git:** [Install Git](https://git-scm.com/book/en/v2/Getting-Started-Installing-Git).
*   **(Optional) WSL 2:** For Windows users, using the Windows Subsystem for Linux (WSL 2) is recommended for better performance and compatibility. Ensure your Docker Desktop is configured to use the WSL 2 backend.

## Setup Steps

1.  **Clone the Repository:**
    ```bash
    git clone <repository-url>
    cd <project-directory>
    ```

2.  **Configure Environment Variables:**
    *   Copy the default environment file:
        ```bash
        cp .env .env.local
        ```
    *   Review and adjust variables in `.env.local` as needed. Key variables include:
        *   `APP_ENV`: Set to `dev` for development.
        *   `APP_SECRET`: A unique random string (Symfony generates one if missing, but it's good practice to set it).
        *   `DATABASE_URL`: Configure your database connection (e.g., `mysql://db_user:db_password@127.0.0.1:3306/db_name?serverVersion=8.0&charset=utf8mb4`). Match the service name (`db` if using the default `compose.yml`), user, password, and database name defined in your Docker Compose setup.
        *   `MAILER_DSN`: Configure mail settings if needed (e.g., `smtp://localhost:1025` for MailHog/Mailpit if using the provided Docker setup).

3.  **Build and Start Docker Containers:**
    ```bash
    # Build and start containers in detached mode
    docker compose up -d --build
    ```
    *   This command builds the PHP application container (`app`), database container (`db`), web server (`nginx`), and potentially other services defined in `compose.yml`.
    *   The `--build` flag ensures images are rebuilt if the `Dockerfile` or related files have changed.
    *   The `-d` flag runs the containers in the background.

4.  **Install Composer Dependencies:**
    *   Run Composer install within the `app` container:
        ```bash
        docker compose run --rm app composer install
        ```
    *   The `--rm` flag automatically removes the temporary container after the command finishes.

5.  **Install Node.js Dependencies:**
    *   Run npm install (or yarn install if using Yarn) within the `app` container (or a dedicated node container if configured):
        ```bash
        # If Node/npm is installed in the app container
        docker compose run --rm app npm install

        # Or if using a separate node container (adjust service name)
        # docker compose run --rm node npm install
        ```

6.  **Database Setup:**
    *   Create the database (if it doesn't exist based on your Docker setup):
        ```bash
        docker compose run --rm app bin/console doctrine:database:create --if-not-exists
        ```
    *   Run database migrations:
        ```bash
        docker compose run --rm app bin/console doctrine:migrations:migrate
        ```
    *   (Optional) Load fixtures if available:
        ```bash
        docker compose run --rm app bin/console doctrine:fixtures:load
        ```

7.  **Build Frontend Assets:**
    *   Run Webpack Encore (or your chosen asset builder):
        ```bash
        # Build for development
        docker compose run --rm app npm run dev

        # Or watch for changes
        # docker compose run --rm app npm run watch
        ```

8.  **Clear Cache (Optional but Recommended):**
    ```bash
    docker compose run --rm app bin/console cache:clear
    ```

9.  **Access the Application:**
    *   Open your web browser and navigate to the URL configured for the Nginx service (commonly `http://localhost` or `http://127.0.0.1`, check the `ports` section in `compose.yml`).

## Common Development Commands

Always run commands through Docker Compose to ensure they execute within the correct environment.

*   **Run Symfony Console Commands:**
    ```bash
    docker compose run --rm app bin/console <command-name> [arguments]
    # Example: List routes
    docker compose run --rm app bin/console debug:router
    ```
*   **Run Composer Commands:**
    ```bash
    docker compose run --rm app composer <command-name> [arguments]
    # Example: Require a new package
    docker compose run --rm app composer require vendor/package
    ```
*   **Run Tests:**
    ```bash
    # Run PHPUnit
    docker compose run --rm -e APP_ENV=test app bin/phpunit

    # Run with coverage report
    docker compose run --rm -e APP_ENV=test app bin/phpunit --coverage-html var/coverage
    ```
*   **Run Linters/Static Analysis:**
    ```bash
    # PHP_CodeSniffer Check
    docker compose run --rm app composer cs-check

    # PHP-CS-Fixer Fix
    docker compose run --rm app composer cs-fix

    # PHPStan Analysis
    docker compose run --rm app composer analyze

    # Psalm Analysis
    docker compose run --rm app composer psalm
    ```
*   **Access Container Shell:**
    ```bash
    # Access bash shell in the 'app' container
    docker compose exec app bash
    ```
*   **View Logs:**
    ```bash
    # View logs for all services
    docker compose logs

    # Follow logs
    docker compose logs -f

    # View logs for a specific service (e.g., app)
    docker compose logs -f app
    ```
*   **Stop Containers:**
    ```bash
    docker compose down
    ```
*   **Stop and Remove Volumes (Use with caution - deletes database data!):**
    ```bash
    docker compose down -v
    ```
