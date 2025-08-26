# Technology Stack

This document provides an overview of the technologies, frameworks, and tools used in this project.

## Backend

*   **PHP:** Version `8.4`.
*   **Symfony:** Version `7.3`.
*   **Doctrine:**
    *   **ORM 3:** (`doctrine/orm`, `doctrine/doctrine-bundle`) with PHP 8 attributes for metadata.
    *   **Migrations:** (`doctrine/doctrine-migrations-bundle`) for incremental schema changes.
*   **Twig:** (`twig/twig`, `symfony/twig-bundle`) The template engine used for rendering HTML views.
*   **Monolog:** (`symfony/monolog-bundle`) Used for logging application events, errors, and debug information.
*   **Guzzle:** (`guzzlehttp/guzzle`) A PHP HTTP client used for making external API requests.
*   **Laminas LDAP:** (`laminas/laminas-ldap`) Used for interacting with LDAP directories (likely for authentication or user synchronization).
*   **PHPSpreadsheet:** (`phpoffice/phpspreadsheet`) Used for reading and writing spreadsheet files (Excel, CSV, etc.).
*   **Sentry:** (`sentry/sentry-symfony`) Used for real-time error tracking and monitoring.

## Frontend

*   **Webpack Encore:** (`@symfony/webpack-encore`, `symfony/webpack-encore-bundle`) Integrates Webpack into the Symfony application for compiling, bundling, and versioning frontend assets (JavaScript, CSS, images).
*   **Stimulus:** (`@hotwired/stimulus`, `@symfony/stimulus-bridge`) A modest JavaScript framework for connecting JavaScript components (controllers) to HTML elements using data attributes.
*   **Sass/SCSS:** (`sass-loader`, `node-sass`, `sass`) A CSS preprocessor used for writing more maintainable and organized stylesheets.
*   **Babel:** (`@babel/core`, `@babel/preset-env`) A JavaScript compiler used to transpile modern JavaScript (ES6+) into backward-compatible versions for older browsers.
*   **Core-js:** (`core-js`) Provides polyfills for modern JavaScript features.

## Development & Tooling

*   **Docker & Docker Compose:** Used to create containerized, reproducible development and production environments.
*   **Composer:** The dependency manager for PHP packages.
*   **npm:** The dependency manager for Node.js packages (used for frontend build tools and libraries).
*   **PHPUnit 12:** (`phpunit/phpunit`) The primary framework for unit, integration, and functional tests.
*   **PHPStan:** (`phpstan/phpstan`) A static analysis tool for PHP, helping to find errors without running the code.
*   **Psalm:** (`vimeo/psalm`) Another static analysis tool for PHP, focused on finding errors and improving code quality.
*   **PHP_CodeSniffer:** (`squizlabs/php_codesniffer`) Checks PHP code against coding standards (e.g., PSR-12).
*   **PHP-CS-Fixer:** (`php-cs-fixer/shim`) Automatically fixes PHP code style issues based on configured rules.
*   **Rector:** (`rector/rector`) A tool for automated code refactoring and upgrades.

## Infrastructure

*   **Nginx:** (`nginx:alpine` Docker image) Used as the web server and reverse proxy, serving static assets and forwarding PHP requests to the application container (PHP-FPM).
*   **MariaDB:** (`mariadb` Docker image) The relational database used to store application data.
