# ADR-002: Doctrine ORM vs Raw SQL Strategy

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Database Team  

## Context

The TimeTracker application requires complex database operations including time entry aggregations, multi-tenant data isolation, performance-critical reporting, and JIRA synchronization. The team needed to decide on the data access strategy balancing developer productivity with performance requirements.

### Requirements
- **Complex Queries**: Time-based aggregations, cross-tenant reporting, hierarchical team structures
- **Performance**: Reports processing 100k+ entries must complete under 5 seconds
- **Type Safety**: Prevent SQL injection, provide compile-time query validation
- **Maintainability**: Clear data access patterns, easy schema evolution
- **Multi-tenancy**: Efficient data isolation and query performance across customers

### Current Performance Challenges
- Export generation for large datasets (>50k entries) taking 30+ seconds
- Complex time aggregation queries causing database locks
- Memory consumption during bulk operations exceeding 512MB
- Inconsistent query performance across different MySQL versions

## Decision

We will use **Doctrine ORM 3.x** as the primary data access layer with **strategic raw SQL optimization** for performance-critical operations.

### Hybrid Approach Strategy

**Doctrine ORM for:**
- Standard CRUD operations (90% of database interactions)
- Entity relationships and lazy loading
- Schema migrations and database abstraction
- Type safety and parameter binding
- Developer productivity and maintainability

**Raw SQL for:**
- Complex aggregation queries (reporting, analytics)
- Bulk operations (imports, exports, synchronization)
- Performance-critical paths identified through profiling
- Database-specific optimizations (indexed queries, stored procedures)

## Implementation Details

### Doctrine ORM Configuration
```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        # Doctrine ORM 3.x uses lazy ghost objects automatically
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        query_cache_driver:
            type: pool
            pool: cache.app
        result_cache_driver:
            type: pool  
            pool: cache.app
```

### Performance-Critical Repository Pattern
```php
class OptimizedEntryRepository extends EntityRepository
{
    // Use Doctrine for standard operations
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
    
    // Use raw SQL for complex aggregations
    public function getMonthlyReportData(User $user, \DateTimeInterface $month): array
    {
        $sql = '
            SELECT 
                DATE(e.day) as date,
                SUM(e.duration) as total_duration,
                COUNT(e.id) as entry_count,
                p.name as project_name
            FROM entries e
            JOIN projects p ON e.project_id = p.id
            WHERE e.user_id = ? 
                AND e.day >= ? 
                AND e.day < ?
            GROUP BY DATE(e.day), p.id
            ORDER BY e.day DESC
        ';
        
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($sql, [$user->getId(), $startDate, $endDate]);
    }
}
```

## Consequences

### Positive
- **Developer Productivity**: Doctrine provides rapid development for standard operations
- **Type Safety**: ORM prevents SQL injection, provides compile-time validation
- **Schema Management**: Automated migrations, database abstraction across MySQL/MariaDB
- **Relationship Management**: Lazy loading, cascade operations, automatic cache invalidation
- **Query Optimization**: Query cache, second-level cache, lazy ghost objects
- **Performance Control**: Raw SQL for critical paths maintains sub-second response times

### Negative
- **Complexity**: Developers must understand both ORM patterns and SQL optimization
- **Memory Usage**: ORM hydration can consume significant memory for large result sets
- **Query Overhead**: Doctrine adds ~10-20ms overhead per query compared to raw SQL
- **Learning Curve**: Team needs expertise in both Doctrine internals and SQL optimization

### Performance Benchmarks

**Standard Operations (Doctrine ORM):**
- Single entity fetch: ~2ms
- Related entity loading: ~5ms with lazy loading
- Standard list queries: ~10-50ms depending on filters

**Optimized Operations (Raw SQL):**
- Complex aggregations: ~100-500ms (vs 2-5s with ORM)
- Bulk exports: ~2-10s (vs 30-60s with ORM)
- Reporting queries: ~200ms-1s (vs 5-15s with ORM)

### Caching Strategy
```php
// Query result caching for expensive operations
class QueryCacheService
{
    public function getCachedAggregation(string $cacheKey, callable $queryCallback): array
    {
        $item = $this->cache->getItem($cacheKey);
        
        if (!$item->isHit()) {
            $result = $queryCallback();
            $item->set($result)->expiresAfter(3600); // 1 hour
            $this->cache->save($item);
        }
        
        return $item->get();
    }
}
```

### Migration Strategy
1. **Phase 1**: Convert all simple CRUD operations to Doctrine ORM
2. **Phase 2**: Identify performance bottlenecks through profiling
3. **Phase 3**: Implement raw SQL for critical paths (reporting, exports)
4. **Phase 4**: Add query caching for frequently accessed data
5. **Phase 5**: Performance testing and optimization iteration

### Quality Gates
- All ORM queries must have corresponding tests
- Raw SQL queries require performance benchmarks
- Query performance monitoring in production
- Memory usage limits for bulk operations (<256MB)
- Database query execution time alerts (>1s)

### Monitoring and Optimization
```php
// Performance monitoring for critical queries
class DatabasePerformanceSubscriber implements EventSubscriber
{
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        if ($event->getDuration() > 1000) { // > 1 second
            $this->logger->warning('Slow query detected', [
                'sql' => $event->getSQL(),
                'duration' => $event->getDuration(),
                'parameters' => $event->getParameters()
            ]);
        }
    }
}
```

This hybrid approach ensures optimal performance for critical operations while maintaining developer productivity and code maintainability for standard database interactions.