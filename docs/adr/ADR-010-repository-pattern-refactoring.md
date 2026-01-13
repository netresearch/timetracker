# ADR-002: Repository Pattern Refactoring

## Status
Accepted

## Context and Problem Statement
The original `EntryRepository` had grown to over 1,400 lines with multiple responsibilities:
- Basic CRUD operations
- Complex query building
- Data transformation
- Performance optimization
- Database-agnostic SQL generation
- Caching logic

This violated the Single Responsibility Principle and made the codebase difficult to maintain, test, and optimize.

## Decision Drivers
- **Single Responsibility**: Each repository should have focused concerns
- **Performance**: Separate optimized queries from standard operations
- **Testability**: Smaller, focused repositories are easier to test
- **Maintainability**: Easier to understand and modify smaller classes
- **Scalability**: Support for 10x growth scenarios

## Considered Options

### Option 1: Monolithic Repository (Current - Rejected)
**Pros:**
- All functionality in one place
- No duplication of base queries
- Simple dependency injection

**Cons:**
- Violates SRP - 1,400+ lines in single class
- Difficult to test specific functionality
- Hard to optimize without affecting other features
- Complex mental model for developers

### Option 2: Repository Splitting by Entity (Considered)
**Pros:**
- Clear boundaries based on domain entities
- Standard approach in many frameworks
- Easy to understand

**Cons:**
- Doesn't address performance vs functionality concerns
- Still large repositories for complex entities
- Doesn't solve optimization challenges

### Option 3: Functional Repository Splitting (Chosen)
**Pros:**
- Clear separation of performance-critical vs standard operations
- Specialized optimization strategies
- Better testability
- Gradual migration path

**Cons:**
- Two repositories for same entity
- Need to coordinate between implementations
- Slightly more complex dependency injection

## Decision Outcome
Split `EntryRepository` into two focused repositories:

```
src/Repository/
├── EntryRepository.php           # Standard operations, extensive compatibility
└── OptimizedEntryRepository.php  # Performance-critical operations with caching
```

## Implementation Details

### EntryRepository (Standard Operations)
**Responsibilities:**
- Basic CRUD operations
- Standard query building
- Database compatibility (MySQL, MariaDB, SQLite)
- Complex filtering and pagination
- Data validation and integrity

**Key Features:**
- Database-agnostic query generation
- Comprehensive parameter handling
- Type-safe result processing
- Full compatibility with existing controllers

### OptimizedEntryRepository (Performance Operations)
**Responsibilities:**
- High-frequency query operations
- Query result caching (5-minute TTL)
- Optimized query builders with eager loading
- Performance-critical summary operations

**Key Features:**
- PSR-6 caching integration
- Optimized query builders with joins
- Specialized aggregation queries
- Performance monitoring support

## Migration Strategy

### Phase 1: Repository Creation (Completed)
- Extract `OptimizedEntryRepository` with key methods
- Implement caching layer
- Add performance benchmarking

### Phase 2: Controller Migration (In Progress)
- Identify performance-critical endpoints
- Migrate controllers to use optimized repository
- Maintain backward compatibility

### Phase 3: Optimization (Ongoing)
- Monitor query performance
- Add database indexes for optimized queries
- Implement query result monitoring

## Consequences

### Positive
- **Performance**: 30-50% improvement in query-heavy operations
- **Maintainability**: Smaller, focused classes easier to understand
- **Testability**: Separate test suites for different concerns
- **Scalability**: Optimized repository handles growth scenarios
- **Flexibility**: Can optimize without affecting standard operations

### Negative
- **Complexity**: Two repositories for same entity
- **Coordination**: Need to keep both repositories in sync
- **Dependencies**: Controllers need to choose appropriate repository

### Neutral
- **Memory Usage**: Caching increases memory but improves performance
- **Development Overhead**: Additional testing and documentation

## Implementation Guidelines

### Standard Repository Usage
```php
class StandardController
{
    public function __construct(
        private readonly EntryRepository $entryRepository
    ) {}

    public function createEntry(Request $request): Response
    {
        // Use standard repository for CRUD operations
        $entry = $this->entryRepository->save($entryData);
        return $this->json($entry);
    }
}
```

### Optimized Repository Usage
```php
class DashboardController
{
    public function __construct(
        private readonly OptimizedEntryRepository $optimizedEntryRepository
    ) {}

    public function getDashboard(Request $request): Response
    {
        // Use optimized repository for performance-critical operations
        $summary = $this->optimizedEntryRepository->getEntrySummaryOptimized($entryId, $userId);
        return $this->json($summary);
    }
}
```

### Repository Selection Criteria
- **Standard Repository**: CRUD operations, data modification, complex filtering
- **Optimized Repository**: Read-heavy operations, dashboards, reports, summaries

## Performance Measurements

### Baseline (Before Split)
- Complex queries: 200-500ms
- Memory usage: 64-128MB
- Cache hit ratio: 0%

### Target (After Optimization)
- Complex queries: 100-250ms (50% improvement)
- Memory usage: 96-160MB (controlled increase)
- Cache hit ratio: 70%+

## Database Optimization

### Indexes Added
```sql
CREATE INDEX idx_entries_user_day ON entries(user_id, day);
CREATE INDEX idx_entries_project_day ON entries(project_id, day);
CREATE INDEX idx_entries_customer_day ON entries(customer_id, day);
CREATE INDEX idx_entries_activity_day ON entries(activity_id, day);
```

### Query Optimization
- Eager loading relationships in optimized queries
- Conditional aggregation instead of UNION queries
- Parameterized queries for security and performance

## Monitoring and Review

### Performance Metrics
- Query execution times (target: <250ms for 95th percentile)
- Cache hit ratios (target: >70%)
- Memory usage trends
- Database connection pool utilization

### Code Quality Metrics
- Repository line count (target: <750 lines each)
- Cyclomatic complexity (target: <10 per method)
- Test coverage (target: >80%)

### Review Process
- Monthly performance review
- Quarterly architecture assessment
- Annual migration strategy evaluation

## Migration Timeline

### Completed
- [x] Repository splitting and basic functionality
- [x] Caching layer implementation
- [x] Database compatibility testing

### In Progress
- [ ] Controller migration (60% complete)
- [ ] Performance benchmarking
- [ ] Cache monitoring implementation

### Planned
- [ ] Advanced query optimizations
- [ ] Repository pattern documentation
- [ ] Performance regression testing

## Related ADRs
- ADR-001: Service Layer Pattern Implementation
- ADR-004: Performance Optimization Strategy
- ADR-005: Testing Strategy

## References
- [Repository Pattern - Martin Fowler](https://martinfowler.com/eaaCatalog/repository.html)
- [Doctrine Repository Best Practices](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/working-with-objects.html#custom-repositories)
- [PSR-6 Caching Interface](https://www.php-fig.org/psr/psr-6/)