# TimeTracker Repository Layer Documentation

This document provides comprehensive documentation for all repository classes in the TimeTracker application, including their methods, query optimization patterns, and relationships.

## Table of Contents

- [Overview](#overview)
- [Repository Pattern Implementation](#repository-pattern-implementation)
- [Query Optimization Techniques](#query-optimization-techniques)
- [Repository Classes](#repository-classes)
- [Common Patterns](#common-patterns)
- [Performance Considerations](#performance-considerations)

## Overview

The TimeTracker application uses 11 repository classes that extend Doctrine's `ServiceEntityRepository` to provide data access layer functionality. Each repository manages a specific entity and provides specialized query methods optimized for the application's requirements.

### Repository Architecture

- **Base**: All repositories extend `Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository`
- **Type Safety**: Explicit type-safe methods for mixed type handling
- **Optimization**: Specialized `OptimizedEntryRepository` for performance-critical operations
- **Caching**: Cache integration in optimized repositories
- **Database Agnostic**: Cross-platform SQL generation for MySQL/MariaDB and SQLite

## Repository Pattern Implementation

All repositories follow consistent patterns:

1. **Type-Safe Access**: Explicit `findOneById()` methods with proper type checking
2. **Structured Outputs**: Methods return structured arrays with nested data formats
3. **Query Builder Usage**: Extensive use of Doctrine Query Builder for complex queries
4. **Raw SQL Integration**: Direct SQL execution for performance-critical operations
5. **Parameter Binding**: Proper parameter binding to prevent SQL injection

## Query Optimization Techniques

### 1. Eager Loading with Joins
```php
// Standard pattern used across repositories
$qb = $this->createQueryBuilder('e')
    ->leftJoin('e.user', 'u')
    ->leftJoin('e.customer', 'c')
    ->leftJoin('e.project', 'p')
    ->leftJoin('e.activity', 'a');
```

### 2. Database-Agnostic Functions
```php
// MySQL/MariaDB
return "YEAR({$field})";
// SQLite
return "strftime('%Y', {$field})";
```

### 3. Caching Strategy
- Cache key patterns: `{prefix}_{operation}_{params}`
- TTL: 300 seconds (5 minutes) for general queries
- 60 seconds for frequently changing work statistics

### 4. Index-Aware Filtering
- Primary filters applied first (user_id, date ranges)
- Secondary filters applied in order of selectivity
- Proper ordering by indexed columns

## Repository Classes

### 1. ActivityRepository

**Purpose**: Manages Activity entities for time tracking categorization

**Entity Managed**: `App\Entity\Activity`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findOneById` | `(int $id): ?Activity` | Type-safe ID lookup |
| `getActivities` | `(): array<int, array{activity: array}>` | Get all activities formatted for API |
| `findOneByName` | `(string $name): ?Activity` | Find activity by name |

**Output Format**:
```php
[
    'activity' => [
        'id' => int,
        'name' => string,
        'needsTicket' => bool,
        'factor' => float|string
    ]
]
```

**Relationships**: Referenced by Entry entities for categorizing time entries.

---

### 2. ContractRepository

**Purpose**: Manages Contract entities for user work agreements

**Entity Managed**: `App\Entity\Contract`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `getContracts` | `(): array<int, array{contract: array}>` | Get all contracts with user joins |

**Query Optimization**:
- Uses JOIN with users table
- Orders by username ASC, then contract start date ASC

**Output Format**:
```php
[
    'contract' => [
        'id' => int,
        'user_id' => int,
        'start' => string|null,  // Y-m-d format
        'end' => string|null,    // Y-m-d format
        'hours_0' => float,      // Sunday hours
        'hours_1' => float,      // Monday hours
        // ... through hours_6 (Saturday)
    ]
]
```

**Relationships**: Each contract belongs to a User entity.

---

### 3. CustomerRepository

**Purpose**: Manages Customer entities and team-based access control

**Entity Managed**: `App\Entity\Customer`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findOneById` | `(int $id): ?Customer` | Type-safe ID lookup |
| `getCustomersByUser` | `(int $userId): array` | Get customers accessible by user |
| `getAllCustomers` | `(): array` | Get all customers with team relationships |
| `findOneByName` | `(string $name): ?Customer` | Find customer by name |

**Access Control Logic**:
```php
// Global customers OR user's team customers
->andWhere('customer.global = 1')
->orWhere('user.id = :userId')
->leftJoin('customer.teams', 'team')
->leftJoin('team.users', 'user')
```

**Output Format**:
```php
[
    'customer' => [
        'id' => int,
        'name' => string,
        'active' => bool,
        'global' => bool,      // Only in getAllCustomers
        'teams' => array<int>  // Only in getAllCustomers
    ]
]
```

**Relationships**:
- Has many Teams (many-to-many)
- Has many Projects (one-to-many)
- Referenced by Entry entities

---

### 4. EntryRepository

**Purpose**: Core repository managing time Entry entities with extensive query capabilities

**Entity Managed**: `App\Entity\Entry`

**Dependencies**:
- `TimeCalculationService` for duration formatting
- `ClockInterface` for date operations

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findOneById` | `(int $id): ?Entry` | Type-safe ID lookup |
| `getEntriesForDay` | `(User $user, string $day): array<Entry>` | Get entries for specific day |
| `getEntriesForMonth` | `(User $user, string $startDate, string $endDate): array<Entry>` | Get entries for date range |
| `getCountByUser` | `(User $user): int` | Count user's total entries |
| `deleteByUserId` | `(User $user): void` | Bulk delete user entries |
| `deleteByActivityId` | `(Activity $activity): void` | Bulk delete activity entries |
| `deleteByProjectId` | `(Project $project): void` | Bulk delete project entries |
| `deleteByCustomerId` | `(Customer $customer): void` | Bulk delete customer entries |
| `findEntriesWithRelations` | `(array $conditions): QueryBuilder` | Create QB with eager loading |
| `findByIds` | `(array $ids): array<Entry>` | Bulk find by IDs |
| `getTotalDuration` | `(array $conditions): float` | Calculate total duration |
| `existsWithConditions` | `(array $conditions): bool` | Check existence |
| `getRawData` | `(string $startDate, string $endDate, ?int $userId): array` | Raw SQL data extraction |
| `getFilteredEntries` | `(array $filters, int $offset, int $limit, string $orderBy, string $orderDirection): array` | Paginated filtered results |
| `getSummaryData` | `(array $filters): array` | Aggregate statistics |
| `getTimeSummaryByPeriod` | `(string $period, array $filters, ?string $startDate, ?string $endDate): array` | Time series data |
| `bulkUpdate` | `(array $entryIds, array $updateData): int` | Bulk update operations |
| `queryByFilterArray` | `(array $arFilter): Query` | Pagination-ready query |
| `findOverlappingEntries` | `(User $user, string $day, string $start, string $end, ?int $excludeId): array` | Validation queries |
| `getEntriesByUser` | `(User $user, int $days, bool $showFuture): array` | Recent entries |
| `findByDate` | `(int $user, int $year, ?int $month, ?int $project, ?int $customer, ?array $arSort): array` | Date-based filtering |
| `findByDatePaginated` | `(int $user, int $year, ?int $month, ?int $project, ?int $customer, ?array $arSort, int $offset, int $limit): array` | Memory-efficient date queries |
| `getWorkByUser` | `(int $userId, Period $period): array` | Work statistics |
| `getActivitiesWithTime` | `(string $ticket): array` | Ticket activity breakdown |
| `getUsersWithTime` | `(string $ticket): array` | Ticket user breakdown |
| `getCalendarDaysByWorkDays` | `(int $workingDays): int` | Working day calculations |
| `findByRecentDaysOfUser` | `(User $user, int $days): array` | Recent work entries |
| `findByUserAndTicketSystemToSync` | `(int $userId, int $ticketSystemId, int $limit): array` | Sync candidate entries |
| `getEntrySummary` | `(int $entryId, int $userId, array $data): array` | Entry context summaries |
| `findByDay` | `(int $userId, string $day): array` | Single day entries |
| `findByFilterArray` | `(array $arFilter): array` | Direct filtered results |

**Database Platform Support**:
The repository provides comprehensive database abstraction:

```php
// MySQL/MariaDB functions
'yearFunction' => 'YEAR({field})',
'monthFunction' => 'MONTH({field})',
'weekFunction' => 'WEEK({field}, 1)',
'concatFunction' => 'CONCAT({fields})',
'dateFormat' => "DATE_FORMAT(e.day, '%d/%m/%Y')",

// SQLite functions
'yearFunction' => "strftime('%Y', {field})",
'monthFunction' => "strftime('%m', {field})",
'weekFunction' => "strftime('%W', {field})",
'concatFunction' => '({fields})',
'dateFormat' => "strftime('%d/%m/%Y', e.day)",
```

**Raw SQL Operations**:
The `getRawData` method uses optimized direct SQL for performance:
- Prepared statements with parameter binding
- Database-specific date formatting
- LEFT JOINs for related entity data
- Type-safe result transformation via `DatabaseResultDto`

**Relationships**: Central entity connecting User, Customer, Project, and Activity.

---

### 5. HolidayRepository

**Purpose**: Manages Holiday entities for calendar operations

**Entity Managed**: `App\Entity\Holiday`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findByMonth` | `(int $year, int $month): array<Holiday>` | Get holidays in date range |

**Date Range Logic**:
```php
$from = new DateTime(sprintf('%04d-%02d-01', $year, $month));
$to = (clone $from)->modify('first day of next month');
```

**Query Optimization**: Uses date range filtering with proper DateTime objects.

**Relationships**: Standalone entity for calendar integration.

---

### 6. OptimizedEntryRepository

**Purpose**: Performance-optimized version of EntryRepository with caching

**Entity Managed**: `App\Entity\Entry`

**Dependencies**:
- `ClockInterface` for date operations
- `CacheItemPoolInterface` for result caching (optional)

**Key Features**:
- **Caching Strategy**: 5-minute TTL for general queries, 1-minute for work stats
- **Eager Loading**: Optimized query builder with preloaded relationships
- **Single Query Aggregation**: Replaces multiple queries with conditional aggregation
- **Index-Aware Filtering**: Optimized filter ordering

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findByRecentDaysOfUser` | `(User $user, int $days): array` | Cached recent entries |
| `findByDate` | `(int $userId, int $year, ?int $month, ?int $projectId, ?int $customerId, ?array $arSort): array` | Optimized date filtering |
| `getEntrySummaryOptimized` | `(int $entryId, int $userId): array` | Single-query summary data |
| `getWorkByUserOptimized` | `(int $userId, Period $period): array` | Cached work statistics |
| `findByFilterArrayOptimized` | `(array $filter): array` | Index-optimized filtering |

**Cache Pattern**:
```php
$cacheKey = sprintf('%s_recent_%d_%d', self::CACHE_PREFIX, $user->getId(), $days);
```

**Optimization Techniques**:

1. **Conditional Aggregation**:
```sql
COUNT(CASE WHEN e.customer_id = :customerId THEN 1 END) as customer_entries,
SUM(CASE WHEN e.customer_id = :customerId THEN e.duration END) as customer_total
```

2. **Eager Loading Query Builder**:
```php
return $this->createQueryBuilder($alias)
    ->select($alias, 'u', 'c', 'p', 'a')
    ->leftJoin($alias . '.user', 'u')
    ->leftJoin($alias . '.customer', 'c')
    ->leftJoin($alias . '.project', 'p')
    ->leftJoin($alias . '.activity', 'a');
```

**Cache Management**: Automatic cache invalidation and type-safe cache retrieval.

**Relationships**: Same as EntryRepository but with performance optimizations.

---

### 7. PresetRepository

**Purpose**: Manages Preset entities for quick entry templates

**Entity Managed**: `App\Entity\Preset`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `getAllPresets` | `(): array<int, array{preset: array}>` | Get all presets ordered by name |

**Output Format**:
```php
[
    'preset' => $preset->toArray()  // Uses entity's toArray() method
]
```

**Query Optimization**: Simple ordered retrieval with entity method delegation.

**Relationships**: Provides templates for creating Entry entities.

---

### 8. ProjectRepository

**Purpose**: Manages Project entities with complex team-based access control

**Entity Managed**: `App\Entity\Project`

**Dependencies**:
- `ArrayTypeHelper` for type-safe array operations

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findOneById` | `(int $id): ?Project` | Type-safe ID lookup |
| `getProjectStructure` | `(int $userId, array $customers): array` | Complex nested project structure |
| `getProjectsByUser` | `(int $userId, int $customerId): array` | User-accessible projects |
| `findByCustomer` | `(int $customerId): array<Project>` | Projects by customer |
| `getAllProjectsForAdmin` | `(): array` | Admin view with customer data |
| `isValidJiraPrefix` | `(string $jiraId): int` | JIRA ID validation |

**Complex Access Control**:
```php
// Projects accessible through global flag OR user team membership
->where('customer.global = 1 OR user.id = :userId')
->leftJoin('project.customer', 'customer')
->leftJoin('customer.teams', 'team')
->leftJoin('team.users', 'user')
```

**Project Structure Algorithm**:
1. Get global projects (accessible to all)
2. Get user-specific projects via team membership
3. Organize by customer ID with 'all' key for complete list
4. Include metadata: JIRA integration, leads, subtickets

**JIRA Integration**: Regex validation for JIRA project prefixes:
```php
'/^([A-Z]+[A-Z0-9]*[, ]*)*$/'
```

**Output Formats**:
- **Standard**: `['project' => $project->toArray()]`
- **Admin View**: Includes customer relationship data
- **Structure**: Nested by customer with comprehensive metadata

**Relationships**:
- Belongs to Customer
- Has many Entry entities
- Connected to TicketSystem for JIRA integration

---

### 9. TeamRepository

**Purpose**: Manages Team entities for user grouping and access control

**Entity Managed**: `App\Entity\Team`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `getAllTeamsAsArray` | `(): array<int, array{team: array}>` | API-formatted team list |
| `findOneByName` | `(string $name): ?Team` | Find team by name |

**Output Format**:
```php
[
    'team' => [
        'id' => int,
        'name' => string,
        'lead_user_id' => int
    ]
]
```

**Query Optimization**: Simple ordered retrieval with safe null handling.

**Relationships**:
- Many-to-many with User entities
- Many-to-many with Customer entities
- Has one lead User

---

### 10. TicketSystemRepository

**Purpose**: Manages TicketSystem entities for external integrations

**Entity Managed**: `App\Entity\TicketSystem`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `getAllTicketSystems` | `(): array<int, array{ticketSystem: array}>` | Get all ticket systems |
| `findOneByName` | `(string $name): ?TicketSystem` | Find system by name |

**Output Format**:
```php
[
    'ticketSystem' => $ticketSystem->toArray()  // Uses entity's toArray() method
]
```

**Exception Handling**: May throw `ReflectionException` during array conversion.

**Relationships**: Referenced by Project entities for JIRA integration.

---

### 11. UserRepository

**Purpose**: Manages User entities with role-based data access

**Entity Managed**: `App\Entity\User`

**Key Methods**:

| Method | Signature | Description |
|--------|-----------|-------------|
| `findOneById` | `(int $id): ?User` | Type-safe ID lookup |
| `findOneByUsername` | `(string $username): ?User` | Username-based lookup |
| `getUsers` | `(int $currentUserId): array` | User list with current user first |
| `getAllUsers` | `(): array` | Complete user list with team data |
| `findOneByAbbr` | `(string $abbr): ?User` | Find by abbreviation |
| `getUserById` | `(int $currentUserId): array` | Single user data |

**User Prioritization Logic**:
```php
if ($currentUserId === $user->getId()) {
    // Set current user on top
    array_unshift($data, ['user' => [...]);
}
```

**Output Format**:
```php
[
    'user' => [
        'id' => int,
        'username' => string,
        'type' => string,        // Enum value
        'abbr' => string,
        'locale' => string,
        'teams' => array<int>    // Only in getAllUsers
    ]
]
```

**Type Safety**: Extensive type checking with instanceof validation.

**Relationships**:
- Many-to-many with Team entities
- Has many Entry entities
- Has many Contract entities
- Can be team leader (lead_user relationship)

## Common Patterns

### 1. Type-Safe Repository Methods
All repositories implement consistent type-safe access:
```php
public function findOneById(int $id): ?EntityType
{
    $result = $this->find($id);
    return $result instanceof EntityType ? $result : null;
}
```

### 2. Structured API Responses
Consistent nested array format:
```php
return [
    'entityName' => [
        'id' => int,
        'name' => string,
        // ... entity-specific fields
    ]
];
```

### 3. Query Builder with Relations
Standard pattern for eager loading:
```php
$qb = $this->createQueryBuilder('alias')
    ->leftJoin('alias.relation', 'r')
    ->where('conditions')
    ->setParameter('param', $value);
```

### 4. Bulk Operations
Consistent bulk operations with query builders:
```php
$this->createQueryBuilder('e')
    ->delete()
    ->where('e.field = :value')
    ->setParameter('value', $value)
    ->getQuery()
    ->execute();
```

## Performance Considerations

### 1. Query Optimization Strategy
- **Index Usage**: Primary filters on indexed columns (user_id, date ranges)
- **Eager Loading**: LEFT JOINs to prevent N+1 queries
- **Batch Operations**: Bulk updates and deletes where possible
- **Raw SQL**: For complex aggregations and reports

### 2. Caching Implementation
- **OptimizedEntryRepository**: Implements PSR-6 cache interface
- **Cache Keys**: Structured with operation and parameter context
- **TTL Strategy**: Short TTL (60s) for frequently changing data, longer (300s) for stable data

### 3. Memory Management
- **Pagination**: Offset/limit patterns for large datasets
- **Streaming**: Direct SQL for large result sets
- **Type Safety**: Explicit type conversion to prevent memory leaks

### 4. Database Portability
- **Platform Detection**: Runtime database platform detection
- **Function Mapping**: Abstract database-specific functions
- **SQL Generation**: Platform-appropriate SQL generation

### 5. Relationship Loading
- **Selective Eager Loading**: Only load required relationships
- **Query Planning**: Optimize JOIN order for performance
- **Lazy Loading Awareness**: Avoid N+1 queries in loops

## Method Reference Table

### EntryRepository (Core Methods)
| Method | Purpose | Performance Notes |
|--------|---------|-------------------|
| `getRawData` | Bulk data extraction | Uses prepared statements, platform-specific SQL |
| `getTimeSummaryByPeriod` | Time series aggregation | Raw SQL with parameterized queries |
| `findEntriesWithRelations` | Eager loading base | Prevents N+1 queries |
| `bulkUpdate` | Mass updates | Single query for multiple records |
| `findOverlappingEntries` | Validation queries | Complex time range logic |

### OptimizedEntryRepository (Performance Methods)
| Method | Purpose | Performance Notes |
|--------|---------|-------------------|
| `getEntrySummaryOptimized` | Single-query summaries | Replaces multiple queries with conditional aggregation |
| `findByFilterArrayOptimized` | Index-aware filtering | Optimized filter ordering |
| `getWorkByUserOptimized` | Cached statistics | 60-second cache TTL |

### ProjectRepository (Complex Methods)
| Method | Purpose | Performance Notes |
|--------|---------|-------------------|
| `getProjectStructure` | Nested project organization | Complex but optimized for UI needs |
| `getProjectsByUser` | Access control queries | Team-based filtering |

This documentation provides a complete reference for the TimeTracker repository layer, covering all 11 repositories with their methods, optimizations, and relationships. The repositories demonstrate advanced Doctrine patterns, performance optimization techniques, and comprehensive type safety measures.