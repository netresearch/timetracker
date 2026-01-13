# Netresearch TimeTracker

[![PHP Version](https://img.shields.io/badge/php-8.4+-blue.svg)](https://www.php.net)
[![Symfony](https://img.shields.io/badge/symfony-7.3-green.svg)](https://symfony.com)
[![License](https://img.shields.io/badge/license-AGPL--3.0-red.svg)](LICENSE)
[![CI Status](https://github.com/netresearch/timetracker/workflows/CI/badge.svg)](https://github.com/netresearch/timetracker/actions)
[![codecov](https://codecov.io/gh/netresearch/timetracker/graph/badge.svg)](https://codecov.io/gh/netresearch/timetracker)
[![Code Quality](https://img.shields.io/badge/phpstan-level%2010-green.svg)](phpstan.neon)
[![Latest Release](https://img.shields.io/github/v/release/netresearch/timetracker)](https://github.com/netresearch/timetracker/releases)
[![OpenSSF Scorecard](https://api.securityscorecards.dev/projects/github.com/netresearch/timetracker/badge)](https://securityscorecards.dev/viewer/?uri=github.com/netresearch/timetracker)
[![OpenSSF Best Practices](https://www.bestpractices.dev/projects/11719/badge)](https://www.bestpractices.dev/projects/11719)
[![Contributor Covenant](https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa.svg)](CODE_OF_CONDUCT.md)
[![SLSA 3](https://slsa.dev/images/gh-badge-level3.svg)](https://slsa.dev)

**Professional time tracking solution for teams and enterprises with advanced LDAP integration, JIRA synchronization, and comprehensive reporting.**

---

## ✨ Features

### 🎯 **Core Time Tracking**
- **Intuitive Entry Management** - Quick time entry with smart autocompletion
- **Bulk Operations** - Efficient handling of vacation, sick leave, and recurring tasks
- **Real-time Validation** - Instant feedback on overlapping entries and constraints
- **Flexible Time Formats** - Support for various time input formats and rounding rules

### 📊 **Advanced Analytics**
- **Multi-level Dashboards** - Personal, project, and company-wide insights
- **Export Integration** - XLSX exports for controlling and compliance
- **Custom Reports** - Flexible reporting with filtering and grouping
- **Performance Metrics** - Track productivity trends and resource allocation

### 🔧 **Enterprise Integration**
- **LDAP/Active Directory** - Seamless authentication with automatic user provisioning
- **JIRA Synchronization** - Bidirectional worklog sync with OAuth 2.0 support
- **Multi-tenant Architecture** - Support for multiple customers and projects
- **External Ticket Systems** - Integration with third-party project management tools

### 👥 **Team Management**
- **Role-based Access Control** - Developer, Controller, and Project Leader roles
- **Team Organization** - Hierarchical team structures with delegation support
- **Service Users** - API access for automated integrations
- **Audit Trail** - Comprehensive logging of all user actions

---

## 🚀 Quick Start

### Using Docker (Recommended)

```bash
# Clone the repository
git clone https://github.com/netresearch/timetracker.git
cd timetracker

# Start development environment
make up

# Install dependencies and initialize database
make install
make db-migrate

# Load sample data (optional)
docker compose exec app php bin/console doctrine:fixtures:load

# Access the application
open http://localhost:8765
```

### Manual Installation

```bash
# Prerequisites: PHP 8.4+, MySQL/MariaDB, Composer, Node.js 18+
git clone https://github.com/netresearch/timetracker.git
cd timetracker

# Install PHP dependencies
composer install

# Install and build frontend assets
npm install
npm run build

# Configure environment
cp .env.example .env.local
# Edit .env.local with your database and LDAP settings

# Initialize database
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# Start development server
symfony server:start
# Or: php -S localhost:8000 -t public/
```

---

## 📋 Requirements

### System Requirements
- **PHP**: 8.4+ with extensions: `ldap`, `pdo_mysql`, `openssl`, `intl`, `json`, `mbstring`
- **Database**: MySQL 8.0+ or MariaDB 10.5+
- **Node.js**: 18+ for asset compilation
- **Memory**: Minimum 512MB, recommended 2GB for development

### Production Requirements
- **Web Server**: Nginx (recommended) or Apache 2.4+
- **PHP-FPM**: Recommended for production deployments
- **SSL/TLS**: Required for secure authentication flows
- **Monitoring**: Compatible with Prometheus, New Relic, Datadog

---

## 🏗️ Architecture

### Technology Stack
- **Backend**: PHP 8.4, Symfony 7.3, Doctrine ORM 3
- **Frontend**: Stimulus, SCSS, Webpack Encore
- **Database**: MySQL/MariaDB with optimized indexes
- **Authentication**: LDAP/AD with JWT token support
- **Testing**: PHPUnit 12, Parallel test execution
- **Code Quality**: PHPStan Level 10, PHP-CS-Fixer, Rector

### Project Structure
```
timetracker/
├── 🏠 src/
│   ├── Controller/         # HTTP endpoints (action-based)
│   ├── Service/           # Business logic layer
│   ├── Entity/            # Doctrine data models
│   ├── Repository/        # Data access layer
│   ├── Security/          # Authentication & authorization
│   └── Dto/               # Data transfer objects
├── 🧪 tests/
│   ├── Unit/              # Unit tests (70% coverage target)
│   ├── Integration/       # Service integration tests
│   └── Controller/        # API endpoint tests
├── 🐳 docker/             # Container configurations
├── 📚 docs/               # Comprehensive documentation
└── 🔧 config/             # Application configuration
```

---

## 📚 Documentation

### 🎓 **Getting Started**
- [**Development Guide**](docs/development.md) - Complete setup guide
- [**Configuration Guide**](docs/configuration.md) - Environment and feature configuration
- [**Architecture Overview**](docs/PROJECT_INDEX.md) - System design and patterns

### 🔧 **Development**
- [**API Documentation**](docs/api.md) - Complete API reference with examples
- [**Testing Guide**](docs/testing.md) - Testing strategy and best practices
- [**Security Guide**](docs/security.md) - Security patterns and implementation

### 🚀 **Operations**
- [**Docker Deployment**](docker/README.md) - Production deployment guide
- [**Performance Tuning**](docs/performance.md) - Optimization guidelines
- [**Monitoring Setup**](docs/monitoring.md) - Observability configuration

---

## 🧪 Testing

The project maintains high code quality with comprehensive testing:

```bash
# Run all tests
make test

# Parallel execution (faster)
make test-parallel

# With coverage report
make coverage

# Performance benchmarks
make perf:benchmark
```

**Current Coverage**: 38% → **Target**: 80%

### Test Categories
- **Unit Tests** (70%): Individual component testing
- **Integration Tests** (25%): Service interaction testing
- **Functional Tests** (5%): End-to-end user journey testing

---

## 🔒 Security

### Authentication & Authorization
- **LDAP/Active Directory** integration with automatic user provisioning
- **JWT tokens** for API authentication with configurable expiration
- **Role-based access control** with fine-grained permissions
- **Service user support** for automated integrations

### Security Features
- **CSRF protection** on all forms and API endpoints
- **Input validation** using Symfony's validation component
- **SQL injection prevention** via Doctrine ORM parameter binding
- **XSS protection** through Twig's auto-escaping

### Compliance
- **GDPR compliance** with data export and deletion capabilities
- **Audit logging** for all sensitive operations
- **Secure token storage** with encryption at rest

---

## 🚀 API Usage

### Authentication
```bash
# Login and get JWT token
curl -X POST http://localhost:8765/api/login \
  -H "Content-Type: application/json" \
  -d '{"username": "john.doe", "password": "secret"}'

# Use token for API calls
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  http://localhost:8765/api/entries
```

### Time Entry Management
```bash
# Create time entry
curl -X POST http://localhost:8765/api/entries \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "start": "09:00",
    "end": "17:00",
    "description": "Feature development",
    "ticket": "PROJ-123",
    "project": 1
  }'

# Get user entries
curl -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  "http://localhost:8765/api/entries?date=2024-01-15"
```

### Bulk Operations
```bash
# Bulk create entries (vacation, sick leave)
curl -X POST http://localhost:8765/api/entries/bulk \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "entries": [
      {"date": "2024-01-15", "preset": "vacation", "duration": 480},
      {"date": "2024-01-16", "preset": "vacation", "duration": 480}
    ]
  }'
```

**📖 Full API Documentation**: [docs/api.md](docs/api.md)

---

## 🤝 Contributing

We welcome contributions! Please see our [Contributing Guidelines](CONTRIBUTING.md) for details.

### Development Workflow
1. **Fork** the repository
2. **Create** a feature branch: `git checkout -b feature/amazing-feature`
3. **Follow** our coding standards: `make check-all`
4. **Write** tests for your changes
5. **Submit** a pull request with clear description

### Code Quality Standards
- **PSR-12** code style with PHP-CS-Fixer
- **PHPStan Level 10** static analysis
- **PHPUnit** tests with >80% coverage target
- **Conventional Commits** for clear change history

```bash
# Check code quality
make check-all

# Fix code style issues
make fix-all

# Run comprehensive test suite
make test-all
```

---

## 📄 License

This project is licensed under the **AGPL-3.0 License** - see the [LICENSE](LICENSE) file for details.

### Commercial Licensing
For commercial use without AGPL restrictions, please contact [licensing@netresearch.de](mailto:licensing@netresearch.de).

---

## 🆘 Support

### Getting Help
- **📚 Documentation**: Comprehensive guides in the [docs/](docs/) directory
- **🐛 Issues**: Report bugs and request features on [GitHub Issues](https://github.com/netresearch/timetracker/issues)
- **💬 Discussions**: Community support on [GitHub Discussions](https://github.com/netresearch/timetracker/discussions)
- **📧 Enterprise**: Commercial support at [support@netresearch.de](mailto:support@netresearch.de)

### Community
- **🌟 Star** this repository if it helps your organization
- **🔀 Fork** and customize for your specific needs
- **🤝 Contribute** improvements back to the community

---

## 🎯 Roadmap

### Upcoming Features
- [ ] **GraphQL API** for flexible data querying
- [ ] **Mobile Apps** for iOS and Android
- [ ] **Advanced Analytics** with ML-powered insights
- [ ] **Slack Integration** for seamless workflow integration
- [ ] **Multi-language Support** for international teams

### Version History
- **v5.x** - Symfony 7.3, PHP 8.4, Modern architecture
- **v4.x** - Symfony 6.x, PHP 8.1+, Legacy maintenance
- **v3.x** - Enhanced JIRA integration, Performance improvements
- **v2.x** - LDAP authentication, Team management
- **v1.x** - Core time tracking functionality

---

<p align="center">
  <b>Built with ❤️ by <a href="https://www.netresearch.de">Netresearch</a></b><br>
  <sub>Empowering teams with professional time tracking since 2015</sub>
</p>
