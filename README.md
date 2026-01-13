# Netresearch TimeTracker

[![PHP Version](https://img.shields.io/badge/php-8.4-blue.svg)](https://www.php.net)
[![Symfony](https://img.shields.io/badge/symfony-7.3-green.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-AGPL--3.0-red.svg)](LICENSE)
[![CI Status](https://github.com/netresearch/timetracker/workflows/CI/badge.svg)](https://github.com/netresearch/timetracker/actions)
[![codecov](https://codecov.io/gh/netresearch/timetracker/graph/badge.svg)](https://codecov.io/gh/netresearch/timetracker)
[![Code Quality](https://img.shields.io/badge/phpstan-level%2010-green.svg)](phpstan.neon)
[![Latest Release](https://img.shields.io/github/v/release/netresearch/timetracker)](https://github.com/netresearch/timetracker/releases)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/netresearch/timetracker/badge)](https://securityscorecards.dev/viewer/?uri=github.com/netresearch/timetracker)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/11719/badge)](https://www.bestpractices.dev/projects/11719)
[![SLSA 3](https://slsa.dev/images/gh-badge-level3.svg)](https://slsa.dev)

**Professional time tracking solution for teams and enterprises with advanced LDAP integration, Jira synchronization, and comprehensive reporting.**

---

## Features

- **Time Entry Management** - Quick time entry with smart autocompletion and real-time validation
- **Bulk Operations** - Efficient handling of vacation, sick leave, and recurring tasks
- **XLSX Export** - Export reports for controlling and compliance
- **LDAP/Active Directory** - Seamless authentication with automatic user provisioning
- **Jira Synchronization** - Bidirectional worklog sync with OAuth 2.0 support
- **Role-based Access Control** - Developer, Controller, and Project Leader roles
- **Multi-tenant Architecture** - Support for multiple customers and projects

---

## Quick Start

### Using Docker (Recommended)

```bash
git clone https://github.com/netresearch/timetracker.git
cd timetracker

make up
make install
make db-migrate

# Access the application
open http://localhost:8765
```

### Manual Installation

```bash
# Prerequisites: PHP 8.4+, MySQL/MariaDB, Composer, Node.js 18+
composer install
npm install && npm run build

cp .env.example .env.local
# Edit .env.local with your database and LDAP settings

php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

symfony server:start
```

---

## Requirements

- **PHP**: 8.4 with extensions: `ldap`, `pdo_mysql`, `intl`, `mbstring`
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Node.js**: 18+ (for asset compilation)

---

## Technology Stack

- **Backend**: PHP 8.4, Symfony 7.3, Doctrine ORM 3
- **Frontend**: Stimulus, SCSS, Webpack Encore
- **Testing**: PHPUnit 12, PHPStan Level 10, PHP-CS-Fixer, Rector
- **Infrastructure**: Docker, GitHub Actions CI/CD

See [docs/techstack.md](docs/techstack.md) for details.

---

## Documentation

| Guide | Description |
|-------|-------------|
| [Development](docs/development.md) | Local setup and development workflow |
| [Configuration](docs/configuration.md) | Environment variables and settings |
| [API Reference](docs/api.md) | REST API endpoints and examples |
| [Testing](docs/testing.md) | Testing strategy and commands |
| [Security](docs/security.md) | Security implementation details |
| [Deployment](docs/DEPLOYMENT_GUIDE.md) | Production deployment guide |
| [Troubleshooting](docs/TROUBLESHOOTING.md) | Common issues and solutions |

---

## Development

```bash
# Run tests
make test

# Run tests in parallel
make test-parallel

# Static analysis & code style
make check-all

# Fix code style
make fix-all
```

### Code Quality Standards

- PSR-12 code style (PHP-CS-Fixer)
- PHPStan Level 10 static analysis
- PHPUnit tests
- Conventional Commits

See [CONTRIBUTING.md](CONTRIBUTING.md) for contribution guidelines.

---

## License

This project is licensed under the **AGPL-3.0 License** - see [LICENSE](LICENSE) for details.

For commercial licensing, contact [licensing@netresearch.de](mailto:licensing@netresearch.de).

---

## Support

- **Documentation**: [docs/](docs/)
- **Issues**: [GitHub Issues](https://github.com/netresearch/timetracker/issues)
- **Discussions**: [GitHub Discussions](https://github.com/netresearch/timetracker/discussions)
- **Enterprise Support**: [support@netresearch.de](mailto:support@netresearch.de)

---

<p align="center">
  <b>Built by <a href="https://www.netresearch.de">Netresearch</a></b>
</p>
