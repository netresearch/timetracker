# 📚 TimeTracker Documentation Index

## Overview
Complete documentation suite for the TimeTracker enterprise time tracking application.

---

## 🚀 Quick Start Guides

### For New Users
1. **[FAQ](./FAQ.md)** - Frequently asked questions and quick answers
2. **[User Guide](./USER_GUIDE.md)** - How to use TimeTracker effectively
3. **[Troubleshooting](./TROUBLESHOOTING.md)** - Common issues and solutions

### For Developers
1. **[Developer Setup](./DEVELOPER_SETUP.md)** - Get running in 5 minutes
2. **[API Usage Guide](./API_USAGE_GUIDE.md)** - REST API documentation
3. **[Testing Strategy](./TESTING_STRATEGY.md)** - How to test effectively

### For Administrators
1. **[Configuration Guide](./CONFIGURATION_GUIDE.md)** - System configuration
2. **[Deployment Guide](./DEPLOYMENT_GUIDE.md)** - Production deployment
3. **[Security Guide](./SECURITY.md)** - Security best practices

---

## 📖 Core Documentation

### Project Documentation
| Document | Description | Audience |
|----------|-------------|----------|
| **[README](../README.md)** | Project overview and quick start | All |
| **[CONTRIBUTING](../CONTRIBUTING.md)** | How to contribute to the project | Developers |
| **[CHANGELOG](./CHANGELOG.md)** | Version history and updates | All |
| **[LICENSE](../LICENSE)** | AGPL-3.0 license terms | All |

### Technical Documentation
| Document | Description | Primary Use |
|----------|-------------|-------------|
| **[API Reference](../claudedocs/API_Reference.md)** | Complete API endpoint documentation | Integration |
| **[Database Schema](../claudedocs/database-schema-documentation.md)** | Entity relationships and schema | Development |
| **[Service Layer](../claudedocs/service-layer-documentation.md)** | Business logic documentation | Development |
| **[Architecture Analysis](../claudedocs/TimeTracker-Architecture-Analysis.md)** | System architecture deep dive | Architecture |

### Architecture Decision Records
| ADR | Title | Status |
|-----|-------|--------|
| **[ADR-001](./adr/ADR-001-php-8-4-symfony-7-3-selection.md)** | PHP 8.4 and Symfony 7.3 Selection | Accepted |
| **[ADR-002](./adr/ADR-002-doctrine-orm-vs-raw-sql.md)** | Doctrine ORM vs Raw SQL Strategy | Accepted |
| **[ADR-003](./adr/ADR-003-jira-integration-architecture.md)** | JIRA Integration Architecture | Accepted |
| **[ADR-004](./adr/ADR-004-authentication-strategy-ldap-local.md)** | Authentication Strategy | Accepted |
| **[ADR-005](./adr/ADR-005-caching-strategy.md)** | Caching Strategy | Accepted |
| **[ADR-006](./adr/ADR-006-testing-philosophy.md)** | Testing Philosophy | Accepted |
| **[ADR-007](./adr/ADR-007-api-design-patterns.md)** | API Design Patterns | Accepted |
| **[ADR-008](./adr/ADR-008-database-performance-optimization.md)** | Database Performance | Accepted |

---

## 🔧 Setup & Configuration

### Environment Setup
1. **[Development Setup](./DEVELOPER_SETUP.md)**
   - Local development environment
   - IDE configuration (PHPStorm, VSCode)
   - Debugging with Xdebug
   - Docker development

2. **[Configuration Guide](./CONFIGURATION_GUIDE.md)**
   - Environment variables
   - LDAP configuration
   - JIRA integration
   - Email settings

3. **[Database Setup](./DATABASE_SETUP.md)**
   - Schema installation
   - Migrations
   - Fixtures and seeding
   - Backup procedures

---

## 🧪 Testing & Quality

### Testing Documentation
- **[Testing Strategy](./TESTING_STRATEGY.md)** - Comprehensive testing approach
- **[Unit Testing Guide](./UNIT_TESTING.md)** - Writing and running unit tests
- **[Integration Testing](./INTEGRATION_TESTING.md)** - API and service testing
- **[Performance Testing](./PERFORMANCE_TESTING.md)** - Load and stress testing

### Code Quality
- **[Code Standards](./CODE_STANDARDS.md)** - PHP, JavaScript, CSS guidelines
- **[PHPStan Configuration](../phpstan.neon)** - Static analysis rules
- **[Laravel Pint Rules](./.pint.json)** - Code style configuration

---

## 🚀 Deployment & Operations

### Deployment Guides
1. **[Deployment Overview](./DEPLOYMENT_GUIDE.md)** - Complete deployment guide
2. **[Docker Deployment](./DOCKER_DEPLOYMENT.md)** - Container-based deployment
3. **[Kubernetes Deployment](./KUBERNETES_DEPLOYMENT.md)** - K8s manifests and setup
4. **[Traditional Deployment](./TRADITIONAL_DEPLOYMENT.md)** - Server-based setup

### Operations
- **[Monitoring Guide](./MONITORING.md)** - Application monitoring
- **[Backup & Recovery](./BACKUP_RECOVERY.md)** - Data protection
- **[Performance Tuning](./PERFORMANCE_TUNING.md)** - Optimization guide
- **[Troubleshooting](./TROUBLESHOOTING.md)** - Problem resolution

---

## 🔐 Security & Compliance

### Security Documentation
- **[Security Overview](./SECURITY.md)** - Security architecture
- **[Authentication Guide](./AUTHENTICATION.md)** - LDAP and local auth
- **[Authorization](./AUTHORIZATION.md)** - Role-based access control
- **[Security Checklist](./SECURITY_CHECKLIST.md)** - Deployment checklist

### Compliance
- **[GDPR Compliance](./GDPR_COMPLIANCE.md)** - Data protection
- **[Audit Logging](./AUDIT_LOGGING.md)** - Activity tracking
- **[Data Retention](./DATA_RETENTION.md)** - Retention policies

---

## 🔌 Integrations

### External Systems
1. **[JIRA Integration](./JIRA_INTEGRATION.md)**
   - OAuth setup
   - Worklog synchronization
   - Troubleshooting

2. **[LDAP Integration](./LDAP_INTEGRATION.md)**
   - Active Directory setup
   - User provisioning
   - Group mapping

3. **[API Integration](./API_USAGE_GUIDE.md)**
   - REST API usage
   - Authentication
   - SDK examples

---

## 📊 Reports & Analytics

### Reporting Documentation
- **[Report Types](./REPORT_TYPES.md)** - Available reports
- **[Custom Reports](./CUSTOM_REPORTS.md)** - Building custom reports
- **[Export Formats](./EXPORT_FORMATS.md)** - CSV, Excel, JSON
- **[Analytics Dashboard](./ANALYTICS_DASHBOARD.md)** - Using analytics

---

## 🛠️ Maintenance

### Maintenance Guides
- **[Upgrade Guide](./UPGRADE_GUIDE.md)** - Version upgrades
- **[Migration Guide](./MIGRATION_GUIDE.md)** - Data migration
- **[Maintenance Tasks](./MAINTENANCE_TASKS.md)** - Regular maintenance
- **[Health Checks](./HEALTH_CHECKS.md)** - System health monitoring

---

## 📚 Reference

### API Documentation
- **[REST API Reference](../claudedocs/API_Reference.md)** - Complete API docs
- **[API Endpoints](../claudedocs/API_Endpoints_Detailed.md)** - Detailed endpoints
- **[API Examples](./API_USAGE_GUIDE.md)** - Usage examples
- **[Webhooks](./WEBHOOKS.md)** - Event notifications

### Database Reference
- **[Entity Reference](../claudedocs/database-schema-documentation.md)** - All entities
- **[ERD Diagram](../claudedocs/entity-relationship-diagram.md)** - Visual schema
- **[Migrations](../migrations/)** - Database migrations

### Code Reference
- **[Service Documentation](../claudedocs/service-layer-documentation.md)** - All services
- **[Controller Reference](./CONTROLLER_REFERENCE.md)** - Controller actions
- **[Repository Methods](./REPOSITORY_REFERENCE.md)** - Data access

---

## 🎓 Learning Resources

### Tutorials
1. **Getting Started**
   - [First Time Setup](./tutorials/FIRST_TIME_SETUP.md)
   - [Your First Time Entry](./tutorials/FIRST_TIME_ENTRY.md)
   - [Using Reports](./tutorials/USING_REPORTS.md)

2. **Advanced Topics**
   - [Bulk Time Entries](./tutorials/BULK_ENTRIES.md)
   - [JIRA Integration](./tutorials/JIRA_SETUP.md)
   - [Custom Reports](./tutorials/CUSTOM_REPORTS.md)

3. **Development**
   - [Creating a Feature](./tutorials/CREATING_FEATURE.md)
   - [Writing Tests](./tutorials/WRITING_TESTS.md)
   - [API Integration](./tutorials/API_INTEGRATION.md)

---

## 📱 User Guides

### By Role
- **[Developer Guide](./guides/DEVELOPER_GUIDE.md)** - For DEV users
- **[Controller Guide](./guides/CONTROLLER_GUIDE.md)** - For CTL users
- **[Admin Guide](./guides/ADMIN_GUIDE.md)** - For PL users

### By Feature
- **[Time Tracking](./guides/TIME_TRACKING.md)** - Core functionality
- **[Reporting](./guides/REPORTING.md)** - Reports and exports
- **[Project Management](./guides/PROJECT_MANAGEMENT.md)** - Projects and customers
- **[Team Management](./guides/TEAM_MANAGEMENT.md)** - Users and teams

---

## 🔍 Quick Links

### Most Visited
- 🚀 [Quick Start](./DEVELOPER_SETUP.md)
- ❓ [FAQ](./FAQ.md)
- 🔧 [Troubleshooting](./TROUBLESHOOTING.md)
- 📊 [API Reference](../claudedocs/API_Reference.md)
- 🔐 [Security Guide](./SECURITY.md)

### For Administrators
- ⚙️ [Configuration](./CONFIGURATION_GUIDE.md)
- 🚢 [Deployment](./DEPLOYMENT_GUIDE.md)
- 📈 [Monitoring](./MONITORING.md)
- 🔄 [Backup](./BACKUP_RECOVERY.md)

### For Developers
- 💻 [Setup Guide](./DEVELOPER_SETUP.md)
- 🧪 [Testing](./TESTING_STRATEGY.md)
- 🔌 [API Usage](./API_USAGE_GUIDE.md)
- 📝 [Contributing](../CONTRIBUTING.md)

---

## 📞 Support

### Getting Help
- **Documentation**: You are here! 📍
- **FAQ**: [Frequently Asked Questions](./FAQ.md)
- **Troubleshooting**: [Common Issues](./TROUBLESHOOTING.md)
- **Slack**: #timetracker-support
- **Email**: timetracker-support@netresearch.de

### Contributing
- **Issues**: [GitHub Issues](https://github.com/netresearch/timetracker/issues)
- **Pull Requests**: [Contributing Guide](../CONTRIBUTING.md)
- **Discussions**: [GitHub Discussions](https://github.com/netresearch/timetracker/discussions)

---

## 📝 Document Status

### Legend
- ✅ **Complete** - Fully documented and up-to-date
- 🚧 **In Progress** - Currently being written/updated
- 📅 **Planned** - Scheduled for creation
- 🔄 **Needs Update** - Requires revision

### Documentation Coverage
- **Core Documentation**: ✅ Complete
- **API Documentation**: ✅ Complete
- **Setup Guides**: ✅ Complete
- **Testing Documentation**: ✅ Complete
- **Security Documentation**: ✅ Complete
- **Integration Guides**: ✅ Complete
- **User Guides**: ✅ Complete
- **Troubleshooting**: ✅ Complete

---

*Last Updated: 2025-01-15 | Documentation Version: 1.0 | TimeTracker Version: 7.3*