# Technology Stack

This document details the core technologies and libraries used in the Netresearch TimeTracker application.

## Backend

*   **PHP 8.2:**
    *   *Why:* The primary server-side language, utilizing modern features for improved type safety, readability, and performance. Adheres to project standards for using the latest stable PHP versions.
*   **Symfony 4.4:**
    *   *Why:* A robust and widely-used PHP framework providing a solid foundation with features like Dependency Injection, Routing, Templating (Twig), Forms, Security, and Console commands. Chosen for its structure, long-term support (LTS), and extensive ecosystem.
*   **Doctrine ORM (~2.20):**
    *   *Why:* Provides powerful object-relational mapping (ORM) and database abstraction (DBAL), simplifying database interactions and schema management through Entities and Migrations.
*   **Doctrine Migrations Bundle (~3.0):**
    *   *Why:* Manages incremental database schema changes in a structured and version-controlled way.
*   **Twig (~2.12|^3.0):**
    *   *Why:* The default template engine for Symfony. Secure, fast, and flexible for rendering HTML views.
*   **Monolog Bundle (~3.1):**
    *   *Why:* Integration for the Monolog logging library, providing flexible logging capabilities to various targets (files, services, etc.).
*   **Laminas LDAP (~2.19):**
    *   *Why:* A library for interacting with LDAP servers, used for the LDAP/Active Directory authentication feature.
*   **PHPUnit (~9.5):**
    *   *Why:* The standard testing framework for PHP, used for unit, integration, and controller tests.
*   **PHPStan (~1.12) & Psalm (~4.30):**
    *   *Why:* Static analysis tools used to detect potential errors and enforce code quality standards without running the code.
*   **PHP_CodeSniffer (~3.7) & PHP-CS-Fixer (~2.19):**
    *   *Why:* Tools to enforce PSR-12 coding standards automatically.
*   **Composer:**
    *   *Why:* The standard dependency manager for PHP projects.

## Frontend

*   **JavaScript (ES6+):**
    *   *Why:* Used for client-side interactivity and dynamic updates.
*   **Webpack Encore Bundle (~1.0):**
    *   *Why:* Symfony's integration with Webpack, simplifying the process of compiling and managing frontend assets (JavaScript, CSS, images).
*   **NPM:**
    *   *Why:* The standard package manager for Node.js and frontend dependencies.
*   **CSS3:**
    *   *Why:* Used for styling the user interface.
*   **(Specific JS/CSS Libraries/Frameworks):** *(Add any major frontend libraries like Bootstrap, jQuery, Vue, React, etc., if used - Currently not obvious from composer.json/package.json)*

## Infrastructure & Environment

*   **Docker & Docker Compose:**
    *   *Why:* Used to create consistent, reproducible development, testing, and production environments through containerization. Simplifies setup and eliminates "works on my machine" issues. Aligns with project standards for command execution.
*   **Nginx:**
    *   *Why:* A high-performance web server used in the Docker setup to serve the application and handle incoming HTTP requests, proxying PHP requests to the `app` container (PHP-FPM).
*   **MariaDB:**
    *   *Why:* An open-source relational database, compatible with MySQL. Used as the primary data store for the application and tests.
*   **Git:**
    *   *Why:* The distributed version control system used for managing the codebase.

## External Services (Integrations)

*   **Jira:**
    *   *Why:* Integrated for work log synchronization via OAuth.
*   **LDAP / Active Directory:**
    *   *Why:* Integrated for user authentication.
*   **Sentry (Optional):**
    *   *Why:* Integrated for real-time error tracking and monitoring.
