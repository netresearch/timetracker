# Netresearch TimeTracker Project Overview

## Purpose
A project and customer-based time tracking application for company employees with comprehensive features for time management, reporting, and integration with external systems.

## Key Features
- Time tracking with autocompletion and bulk entry (sickness/vacation)
- Per-user, per-project, and company-wide charts and statistics
- Administration interface for customers, projects, users, and teams
- XLSX export for controlling tasks
- AD/LDAP authentication
- Jira integration for work log entries
- Multi-role system (DEV, CTL, PL)
- Service user support for API operations

## Technology Stack
- **Backend**: PHP 8.4, Symfony 7.3, Doctrine ORM 3
- **Database**: MySQL/MariaDB
- **Frontend**: Twig templates, JavaScript, Webpack
- **Authentication**: LDAP/AD integration
- **Infrastructure**: Docker Compose for local development, Nginx proxy
- **Testing**: PHPUnit 12
- **Static Analysis**: PHPStan (level 8), Psalm
- **Code Style**: PHP_CodeSniffer, PHP-CS-Fixer
- **Package Management**: Composer (PHP), npm (JS)

## Architecture
- MVC pattern with Symfony framework
- Repository pattern for data access
- Service layer for business logic
- Event-driven architecture with EventDispatcher
- DTO pattern for data transfer and validation
- Modern PHP features (attributes, typed properties)

## User Roles
- **DEV** (Developer): Track times, bulk entries, view charts
- **CTL** (Controller): Includes DEV + export to CSV
- **PL** (Project Leader): Includes CTL + full administration

## Environment
- Development using Docker Compose
- Linux-based system
- Test environment configuration available
- Multiple environment files (.env, .env.dev, .env.test)