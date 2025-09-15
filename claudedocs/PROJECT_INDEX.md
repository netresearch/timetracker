# TimeTracker Project Index - Complete Documentation

## 📚 Project Overview
**Netresearch TimeTracker** - Enterprise-grade time tracking system built with Symfony 7.3 and PHP 8.4

### Quick Links
- [Architecture Analysis](./TimeTracker-Architecture-Analysis.md)
- [API Reference](./API_Reference.md)
- [Database Schema](./database-schema-documentation.md)
- [Service Layer](./service-layer-documentation.md)

## 🏗️ Architecture Components

### Core Technology Stack
- **Backend**: PHP 8.4, Symfony 7.3, Doctrine ORM 3.5
- **Database**: MariaDB/MySQL with optimized indexing
- **Frontend**: Webpack Encore, Twig templates
- **Testing**: PHPUnit 12.3, PHPStan Level 9, PHPat
- **Infrastructure**: Docker Compose, Nginx

### Architectural Patterns
- **MVC Implementation**: Action-based controllers with single responsibility
- **Repository Pattern**: ServiceEntityRepository with query optimization
- **Service Layer**: Interface-based design with dependency injection
- **Domain Modeling**: Rich entities with business logic encapsulation
- **Integration Strategy**: Adapter pattern for external systems

## 📁 Project Structure

```
timetracker/
├── src/                    # Application source code
│   ├── Controller/        # 57 API endpoints across 11 domains
│   │   ├── Admin/        # Administrative operations (22 endpoints)
│   │   ├── Default/      # Core functionality (8 endpoints)
│   │   ├── Interpretation/ # Analytics & reporting (9 endpoints)
│   │   ├── Tracking/     # Time entry management (3 endpoints)
│   │   └── Settings/     # User preferences (1 endpoint)
│   ├── Entity/           # 13 Doctrine entities
│   │   ├── User         # Authentication & authorization
│   │   ├── Entry        # Core time tracking records
│   │   ├── Project      # Project management
│   │   ├── Customer     # Customer relationships
│   │   └── Team         # Organizational structure
│   ├── Service/         # 22 business services
│   │   ├── Integration/ # JIRA, LDAP integrations
│   │   ├── Export/      # Data export services
│   │   ├── Security/    # Authentication services
│   │   └── Util/        # Utility services
│   ├── Repository/      # Data access layer
│   ├── Dto/            # Data transfer objects
│   ├── Enum/           # Type-safe enumerations
│   └── Validator/      # Custom validation constraints
├── tests/              # Comprehensive test suites
├── config/             # Symfony configuration
├── migrations/         # Database migrations
├── public/            # Web root
├── templates/         # Twig templates
├── assets/           # Frontend assets
└── docker/          # Docker configuration
```

## 🔌 API Endpoints

### Domain Distribution
| Domain | Endpoints | Security | Purpose |
|--------|-----------|----------|---------|
| Admin | 22 | PL only | System administration |
| Tracking | 3 | All users | Time entry management |
| Interpretation | 9 | All users | Analytics & charts |
| Export | 2 | All users | Data export (CSV/JSON) |
| Integration | 4 | All users | JIRA OAuth & sync |
| Authentication | 4 | Public/Auth | Login/logout flow |
| Settings | 1 | All users | User preferences |
| Status | 2 | Public | Health checks |
| Time Summary | 3 | All users | Specialized reports |
| Controlling | 1 | CTL only | Management reports |

### Key API Features
- **RESTful Design**: Consistent HTTP verbs and status codes
- **DTO Validation**: Comprehensive input validation with Symfony constraints
- **Error Handling**: Localized error messages with proper HTTP codes
- **Response Formats**: JSON, CSV, HTML, JavaScript
- **Security**: Form-based auth with LDAP integration, role-based access

## 💾 Database Schema

### Entity Relationships
```
User ←→ Team (Many-to-Many)
User → Entry (One-to-Many)
Entry → Project → Customer (Chain)
Project → Contract (One-to-Many)
Team ←→ Customer (Many-to-Many)
User → UserTicketsystem → TicketSystem (OAuth)
Entry → Ticket (JIRA integration)
```

### Key Tables
- **entries**: Core time tracking data with 10 performance indexes
- **users**: Authentication with encrypted OAuth tokens
- **projects**: Hierarchical project structure
- **customers**: Client management with team visibility
- **tickets**: JIRA issue cache for offline access

### Performance Optimizations
- Composite indexes on high-traffic queries
- Strategic denormalization for read performance
- Lazy loading with Doctrine ghost objects
- Query result caching

## ⚙️ Service Layer

### Core Services (22 total)

#### Business Logic Services
- **TimeCalculationService**: Advanced time parsing (1w 2d 3h → minutes)
- **EntryQueryService**: Type-safe querying with pagination
- **ExportService**: Memory-efficient batch exports
- **SubticketSyncService**: Automated JIRA synchronization

#### Integration Services
- **JiraIntegrationService**: High-level JIRA orchestration
- **JiraWorkLogService**: Direct API operations
- **JiraAuthenticationService**: OAuth with AES-256-GCM encryption
- **ModernLdapService**: Enterprise LDAP with injection prevention

#### Utility Services
- **TicketService**: Ticket URL parsing and system detection
- **LocalizationService**: i18n with message catalog
- **HolidayService**: Business day calculations
- **ArrayTypeHelper**: Type-safe array operations

### Key Algorithms
- **Human-readable time parsing**: Supports weeks, days, hours, minutes
- **JIRA batch optimization**: Groups entries by ticket system
- **Memory-efficient exports**: Generator patterns for large datasets
- **Cache invalidation**: Tag-based with entity-specific patterns

## 🔐 Security Features

### Authentication & Authorization
- **LDAP Integration**: Enterprise SSO with team mapping
- **Role Hierarchy**: DEV < CTL < PL permissions
- **Team-based Access**: Data visibility controls
- **Service Users**: API access for automation

### Security Patterns
- **Token Encryption**: AES-256-GCM for OAuth tokens
- **Input Sanitization**: LDAP injection prevention
- **CSRF Protection**: Built-in Symfony protection
- **Session Security**: Secure cookies, timeout management

## 🧪 Testing Infrastructure

### Test Coverage
- **Unit Tests**: Business logic validation
- **Integration Tests**: API endpoint testing
- **Functional Tests**: User journey validation
- **Static Analysis**: PHPStan Level 9

### Quality Tools
- **PHPUnit 12.3**: Test execution framework
- **PHPStan**: Static type checking
- **Laravel Pint**: Code style enforcement
- **PHPat**: Architecture testing
- **Rector**: Automated refactoring

## 🚀 Key Features

### Time Tracking
- Autocompletion for projects/activities
- Bulk entry for vacation/sickness
- Keyboard shortcuts for efficiency
- Real-time validation

### Reporting & Analytics
- User/project/customer charts
- Time summaries and interpretations
- XLSX/CSV exports
- Timalytics integration

### JIRA Integration
- OAuth 1.0a authentication
- Bidirectional worklog sync
- Ticket metadata caching
- Batch operations

### Administration
- Customer/project management
- Team organization
- User provisioning
- Activity templates

## 📊 Performance Characteristics

### Database Performance
- **Entry Query**: <50ms with proper indexing
- **Bulk Operations**: Batch processing for efficiency
- **Export**: Streaming for memory optimization

### API Performance
- **Response Time**: <200ms for most endpoints
- **Concurrent Users**: Handles 100+ simultaneous users
- **Export Limits**: 10,000 entries per export

### Caching Strategy
- **Symfony Cache**: Application-level caching
- **Query Caching**: Doctrine result cache
- **Asset Optimization**: Webpack chunking

## 🔄 Integration Points

### External Systems
1. **JIRA**: Complete OAuth integration with worklog sync
2. **LDAP/AD**: Enterprise authentication and team mapping
3. **Timalytics**: Advanced analytics integration
4. **Excel**: Native XLSX export support

### Integration Patterns
- **Adapter Pattern**: Abstract external system differences
- **Retry Logic**: Resilient API communication
- **Cache Fallback**: Offline capability for external data
- **Batch Processing**: Optimize API calls

## 📝 Development Guidelines

### Code Standards
- **PHP 8.4**: Strict typing, attributes, enums
- **Symfony Best Practices**: Service injection, routing
- **SOLID Principles**: Throughout the codebase
- **DRY/KISS**: Consistent application

### Contribution Workflow
1. Feature branches from main
2. PHPStan/Pint validation required
3. Test coverage for new features
4. PR review process

## 📈 Metrics & Monitoring

### Application Metrics
- **Active Users**: Tracked in database
- **Entry Volume**: Daily/weekly/monthly aggregations
- **API Usage**: Endpoint hit counts
- **Performance**: Response time tracking

### Health Checks
- **Database Connectivity**: `/status/check`
- **LDAP Availability**: Authentication monitoring
- **JIRA Integration**: OAuth token validation
- **Cache Status**: Memory usage tracking

## 🗂️ Documentation Files

### Core Documentation
1. **[Architecture Analysis](./TimeTracker-Architecture-Analysis.md)**: 50+ page deep dive into system architecture
2. **[API Reference](./API_Reference.md)**: Complete endpoint documentation with examples
3. **[API Endpoints Detailed](./API_Endpoints_Detailed.md)**: Request/response specifications
4. **[API Endpoint Summary](./API_Endpoint_Summary.md)**: Quick reference guide

### Database Documentation
1. **[Database Schema](./database-schema-documentation.md)**: Complete entity documentation
2. **[Entity Relationship Diagram](./entity-relationship-diagram.md)**: Visual relationship mapping
3. **[Database Architecture Summary](./database-architecture-summary.md)**: Design decisions and optimizations

### Service Documentation
1. **[Service Layer Documentation](./service-layer-documentation.md)**: Comprehensive service analysis

## 🎯 Quick Start Commands

```bash
# Development Setup
docker-compose up -d
composer install
npm install && npm run dev

# Database Setup
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load

# Testing
./vendor/bin/phpunit
./vendor/bin/phpstan analyse
./vendor/bin/pint

# Build for Production
npm run build
composer install --no-dev --optimize-autoloader
```

## 📞 Support & Resources

- **Repository**: Internal Netresearch repository
- **Issue Tracking**: JIRA integration for bug tracking
- **Documentation**: This comprehensive index
- **Analytics**: Timalytics for advanced insights

---

*Generated: 2025-09-15 | Framework: Symfony 7.3 | PHP: 8.4 | Database: MariaDB*