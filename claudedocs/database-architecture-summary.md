# TimeTracker Database Architecture Summary

## Executive Summary

The TimeTracker application implements a sophisticated relational database schema centered around time entry management with comprehensive integration capabilities. The architecture prioritizes data integrity, performance optimization, and secure external system integration while maintaining audit-friendly historical data preservation.

## Core Architecture Principles

### 1. Entity-Centric Design
- **Central Entity**: `Entry` table serves as the primary data hub
- **Supporting Entities**: User, Project, Customer, Activity provide categorization
- **Integration Entities**: TicketSystem, UserTicketsystem enable external connectivity
- **Organizational Entities**: Team, Contract, Account support business structure

### 2. Performance-First Approach
- Comprehensive indexing strategy on high-volume Entry table
- Composite indexes for common query patterns (user+date, user+project)
- Strategic denormalization for frequently accessed data
- Efficient date range and time-based queries

### 3. Security and Compliance
- Encrypted token storage for external system authentication
- Role-based access control through UserType enumeration
- Team-based visibility controls for customer data
- Audit trail preservation through soft delete patterns

## Key Architectural Decisions

### Data Model Decisions

**1. Entry as Central Entity**
```php
// Entry contains all necessary foreign keys for complete context
Entry {
    user_id (who)
    project_id (what)  
    customer_id (for whom)
    activity_id (how)
    account_id (billing)
    day/start/end (when)
}
```
*Rationale*: Enables efficient querying and reporting without complex joins for basic operations.

**2. Flexible Customer-Project Relationship**
```php
// Entry can reference both Customer and Project
Entry->customer_id (direct customer billing)
Entry->project_id->customer_id (project-based billing)
```
*Rationale*: Supports both project-based and direct customer billing models.

**3. Enumeration-Based Type Safety**
```php
UserType enum: ADMIN, PL, DEV, USER
EntryClass enum: PLAIN, DAYBREAK, PAUSE, OVERLAP  
BillingType enum: Various billing methods
```
*Rationale*: Provides type safety, validation, and business logic encapsulation.

### Integration Architecture

**4. Multi-Tenant Ticket System Support**
```php
TicketSystem (1) ← UserTicketsystem (M) → User (1)
```
*Rationale*: Each user can have personalized connections to multiple external systems.

**5. Token Security Design**
- OAuth tokens encrypted before database storage
- TEXT fields support variable-length encrypted data
- Per-user token isolation prevents cross-contamination

**6. Caching Strategy**
```php
Ticket entity caches external system data
Entry stores worklog_id for bidirectional sync
```
*Rationale*: Reduces external API calls while maintaining sync capability.

## Performance Optimizations

### Indexing Strategy

**Primary Query Patterns Addressed:**
1. **User Daily Entries**: `idx_entries_user_day (user_id, day DESC)`
2. **Date Range Reports**: `idx_entries_day (day)`
3. **Project Analysis**: `idx_entries_project (project_id)`
4. **Billing Queries**: `idx_entries_customer (customer_id)`
5. **Time Ordering**: `idx_entries_day_start (day DESC, start DESC)`

**Composite Indexes for Complex Queries:**
- `idx_entries_user_project`: User productivity by project
- `idx_entries_user_sync`: Sync status monitoring
- Multi-column indexes reduce query complexity

### Query Performance Characteristics

```sql
-- Optimized: User's entries for date range
SELECT * FROM entries 
WHERE user_id = ? AND day BETWEEN ? AND ?
-- Uses: idx_entries_user_day

-- Optimized: Project time summary  
SELECT SUM(duration) FROM entries 
WHERE project_id = ? AND day >= ?
-- Uses: idx_entries_project

-- Optimized: Sync status report
SELECT COUNT(*) FROM entries 
WHERE user_id = ? AND synced_to_ticketsystem = false
-- Uses: idx_entries_user_sync
```

## Security Architecture

### Authentication Integration
- **LDAP Integration**: UserInterface implementation for enterprise auth
- **Remember Me**: Hash generation for session persistence
- **Role Mapping**: UserType enum provides Symfony role mapping

### Data Protection
- **Token Encryption**: All OAuth tokens encrypted at rest
- **Key Management**: Public/private key pairs for OAuth flows  
- **Access Isolation**: Team-based customer visibility controls

### Audit and Compliance
- **Historical Preservation**: No hard deletes on critical entities
- **Change Tracking**: Entry modifications maintain audit trail
- **Data Lineage**: Foreign key relationships preserve context

## Scalability Considerations

### Horizontal Scaling Readiness
- **Stateless Design**: No session state in database
- **Caching Support**: External ticket data cached locally
- **Index Efficiency**: Composite indexes support high-volume queries

### Data Growth Management
- **Partitioning Ready**: Date-based partitioning possible on Entry table
- **Archive Strategy**: Historical data preservation with query optimization
- **Cleanup Patterns**: Soft delete allows for data lifecycle management

### Performance Monitoring Points
- Entry table growth rate (primary concern)
- Index usage patterns
- External system API call frequency
- Token refresh and expiration cycles

## Integration Patterns

### External Ticket Systems
```php
User → UserTicketsystem → TicketSystem → External API
  ↓                                         ↓
Entry ← Bidirectional Sync ← Worklog ← External Ticket
```

**Key Features:**
- Per-user OAuth credentials
- Bidirectional time sync
- Ticket data caching
- Connection error handling

### Reporting and Analytics
```php
// Multi-dimensional analysis support
User → Entry → Project → Customer (team productivity)
Activity → Entry → User → Team (activity analysis)
Customer → Project → Entry → User (billing reports)
```

### Business Intelligence
- Time aggregation by multiple dimensions
- Billable vs non-billable time analysis
- Project progress and estimation tracking
- Team productivity metrics

## Migration and Versioning Strategy

### Schema Evolution Pattern
```php
Version20250901_AddPerformanceIndexes: Query optimization
Version20250901_EncryptTokenFields: Security enhancement
```

**Migration Characteristics:**
- Transactional migrations for data consistency
- Performance-focused index additions
- Security-driven field expansions
- Backward compatibility considerations

### Data Migration Considerations
- Token field expansion (VARCHAR→TEXT) for encryption
- Index creation on large tables (Entry)
- Historical data preservation during schema changes

## Risk Assessment and Mitigation

### Performance Risks
- **Entry Table Growth**: Mitigated by comprehensive indexing
- **Complex Joins**: Reduced by denormalized foreign keys
- **External API Latency**: Addressed by local caching

### Security Risks  
- **Token Exposure**: Mitigated by encryption at rest
- **Unauthorized Access**: Controlled by team-based visibility
- **Data Leakage**: Prevented by role-based permissions

### Data Integrity Risks
- **Orphaned Records**: Prevented by foreign key constraints
- **Invalid Relationships**: Validated by business logic
- **Time Overlap**: Detected by EntryClass enumeration

## Recommendations

### Immediate Optimizations
1. Monitor Entry table growth and consider partitioning
2. Implement connection pooling for external API calls
3. Add monitoring for token refresh cycles

### Future Enhancements
1. **Read Replicas**: For reporting queries
2. **Data Warehousing**: For historical analysis
3. **API Rate Limiting**: For external system protection
4. **Real-time Sync**: WebSocket-based updates

### Monitoring Requirements
- Query performance metrics on indexed operations
- External system integration health checks
- Token expiration and refresh monitoring
- Data growth rate tracking

This architecture provides a robust foundation for time tracking with enterprise-grade security, performance, and integration capabilities while maintaining the flexibility to evolve with changing business requirements.