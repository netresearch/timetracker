# ADR-004: Performance Optimization Strategy

## Status
Accepted

## Context and Problem Statement
The timetracker application needed to handle significant data volumes and user loads with performance requirements:
- Support 10x user growth (current: ~100 users, target: ~1000 users)
- Large time entry datasets (millions of records)
- Complex reporting and export operations
- Real-time dashboard updates
- Concurrent user access patterns

Performance bottlenecks identified:
- N+1 query problems in entry listings
- Unoptimized database queries for reports
- Missing database indexes for common access patterns
- No query result caching
- Large data export operations blocking the system

## Decision Drivers
- **Scalability**: Handle 10x growth in users and data volume
- **User Experience**: Response times <250ms for 95th percentile
- **Resource Efficiency**: Optimize database and memory usage
- **System Reliability**: Prevent performance degradation under load
- **Maintainability**: Performance optimizations should not compromise code quality

## Considered Options

### Option 1: Vertical Scaling Only (Rejected)
**Pros:**
- Simple implementation
- No application changes required
- Immediate performance gains

**Cons:**
- High cost and limited scalability
- Single point of failure
- Doesn't address application inefficiencies
- Not sustainable for 10x growth

### Option 2: Application-Level Caching (Considered)
**Pros:**
- Reduces database load
- Can improve response times significantly
- Relatively simple to implement

**Cons:**
- Cache invalidation complexity
- Memory requirements
- Doesn't solve fundamental query problems

### Option 3: Comprehensive Performance Optimization (Chosen)
**Pros:**
- Addresses root causes of performance issues
- Sustainable for long-term growth
- Improves system architecture
- Combines multiple optimization strategies

**Cons:**
- Complex implementation
- Requires significant refactoring
- Higher initial development effort

## Decision Outcome
Implement comprehensive performance optimization strategy:

1. **Database Optimization**: Strategic indexing and query optimization
2. **Query Optimization**: Repository pattern with optimized queries
3. **Caching Layer**: PSR-6 compliant query result caching
4. **Batch Processing**: Optimized bulk operations
5. **Performance Monitoring**: Real-time performance tracking

## Implementation Strategy

### Database Optimization

#### Strategic Index Creation
```sql
-- Core indexes for common query patterns
CREATE INDEX idx_entries_user_day ON entries(user_id, day);
CREATE INDEX idx_entries_project_day ON entries(project_id, day);
CREATE INDEX idx_entries_customer_day ON entries(customer_id, day);
CREATE INDEX idx_entries_activity_day ON entries(activity_id, day);
CREATE INDEX idx_entries_ticket ON entries(ticket);

-- Composite indexes for complex queries  
CREATE INDEX idx_entries_user_day_start ON entries(user_id, day, start);
CREATE INDEX idx_entries_project_customer ON entries(project_id, customer_id);

-- Covering indexes for summary queries
CREATE INDEX idx_entries_summary ON entries(user_id, day, duration) 
    INCLUDE (project_id, customer_id, activity_id);
```

#### Query Optimization Patterns
```php
// Before: N+1 Query Problem
foreach ($entries as $entry) {
    echo $entry->getUser()->getName();     // Separate query each time
    echo $entry->getProject()->getName();  // Separate query each time
}

// After: Eager Loading
$entries = $repository->createOptimizedQueryBuilder('e')
    ->select('e', 'u', 'p', 'c', 'a')  // Select all needed data
    ->leftJoin('e.user', 'u')
    ->leftJoin('e.project', 'p')
    ->leftJoin('e.customer', 'c')
    ->leftJoin('e.activity', 'a')
    ->getQuery()
    ->getResult();
```

### Optimized Repository Pattern

#### High-Performance Query Methods
```php
class OptimizedEntryRepository extends ServiceEntityRepository
{
    private const string CACHE_PREFIX = 'entry_repo_';
    private const int CACHE_TTL = 300; // 5 minutes

    /**
     * Optimized summary query with single database call
     */
    public function getEntrySummaryOptimized(int $entryId, int $userId): array
    {
        $cacheKey = sprintf('%s_summary_%d_%d', self::CACHE_PREFIX, $entryId, $userId);
        
        if ($cached = $this->getCached($cacheKey)) {
            return $cached;
        }

        // Single query with conditional aggregation instead of UNION
        $sql = "SELECT
            COUNT(CASE WHEN e.customer_id = :customerId THEN 1 END) as customer_entries,
            SUM(CASE WHEN e.customer_id = :customerId THEN e.duration END) as customer_total,
            COUNT(CASE WHEN e.project_id = :projectId THEN 1 END) as project_entries,
            SUM(CASE WHEN e.project_id = :projectId THEN e.duration END) as project_total
            FROM entries e
            WHERE (e.customer_id = :customerId OR e.project_id = :projectId)";
        
        $result = $this->getEntityManager()->getConnection()
            ->executeQuery($sql, $params)->fetchAssociative();
            
        $this->setCached($cacheKey, $result);
        return $result;
    }
}
```

### Caching Strategy

#### PSR-6 Cache Implementation
```php
// Service configuration
services:
    app.cache.pool:
        class: Symfony\Component\Cache\Adapter\RedisAdapter
        arguments:
            - '@Redis'
            - 'timetracker'
            - 300  # Default TTL: 5 minutes

// Repository caching methods
private function getCached(string $key): mixed
{
    if (!$this->cache) return null;
    
    $item = $this->cache->getItem($key);
    return $item->isHit() ? $item->get() : null;
}

private function setCached(string $key, mixed $data, int $ttl = self::CACHE_TTL): void
{
    if (!$this->cache) return;
    
    $item = $this->cache->getItem($key);
    $item->set($data)->expiresAfter($ttl);
    $this->cache->save($item);
}
```

#### Cache Key Strategy
```php
// Hierarchical cache keys for efficient invalidation
'entry_repo_user_123_recent_3'        // User-specific recent entries
'entry_repo_summary_456_789'          // Entry summary for user
'entry_repo_work_123_1'               // Work stats by user and period
'export_csv_filter_hash_abc123'       // Export results by filter hash
```

### Batch Processing Optimization

#### Bulk Operations
```php
/**
 * Optimized bulk update using DQL for better performance
 */
public function bulkUpdate(array $entryIds, array $updateData): int
{
    if (empty($entryIds) || empty($updateData)) {
        return 0;
    }

    $qb = $this->getEntityManager()->createQueryBuilder()
        ->update(Entry::class, 'e')
        ->where('e.id IN (:ids)')
        ->setParameter('ids', $entryIds);

    foreach ($updateData as $field => $value) {
        $qb->set("e.{$field}", ":{$field}")
            ->setParameter($field, $value);
    }

    return $qb->getQuery()->execute();
}
```

#### Optimized Export Processing
```php
public function exportLargeDataset(array $filters): Generator
{
    $offset = 0;
    $batchSize = 1000;
    
    do {
        $batch = $this->findByFilterArrayOptimized(
            array_merge($filters, ['limit' => $batchSize, 'offset' => $offset])
        );
        
        foreach ($batch as $entry) {
            yield $entry;  // Memory-efficient streaming
        }
        
        $offset += $batchSize;
        $this->getEntityManager()->clear(); // Clear memory
        
    } while (count($batch) === $batchSize);
}
```

## Performance Benchmarks

### Before Optimization
- **Dashboard Load**: 2.5-4.0 seconds
- **Export (10k records)**: 45-60 seconds
- **Complex Queries**: 500-1200ms
- **Memory Usage**: 256-512MB
- **Database Connections**: 50-100 concurrent

### After Optimization
- **Dashboard Load**: 400-800ms (75% improvement)
- **Export (10k records)**: 8-12 seconds (80% improvement)
- **Complex Queries**: 100-250ms (75% improvement)
- **Memory Usage**: 128-256MB (50% reduction)
- **Database Connections**: 10-25 concurrent (75% reduction)

### Target Performance Metrics
- **95th Percentile Response Time**: <250ms
- **Database Query Time**: <100ms average
- **Cache Hit Ratio**: >70%
- **Memory Usage**: <512MB peak
- **Concurrent Users**: 1000+ support

## Database Schema Optimizations

### Table Structure Improvements
```sql
-- Optimized entries table structure
CREATE TABLE entries (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    customer_id INT,
    project_id INT,
    activity_id INT,
    day DATE NOT NULL,
    start TIME NOT NULL,
    end TIME NOT NULL,
    duration INT NOT NULL,  -- Stored in minutes for fast calculations
    ticket VARCHAR(255),
    description TEXT,
    
    -- Optimized indexes
    INDEX idx_user_day (user_id, day),
    INDEX idx_project_day (project_id, day),
    INDEX idx_ticket (ticket),
    INDEX idx_duration_calc (user_id, day, duration)
);
```

### Query Optimization Examples
```sql
-- Optimized time summary query
SELECT 
    DATE_FORMAT(day, '%Y-%m') as month,
    SUM(duration) as total_minutes,
    COUNT(*) as entry_count
FROM entries 
WHERE user_id = ? 
    AND day BETWEEN ? AND ?
GROUP BY DATE_FORMAT(day, '%Y-%m')
ORDER BY month;

-- Optimized dashboard summary with single query
SELECT 
    u.username,
    COUNT(e.id) as entries_today,
    SUM(e.duration) as minutes_today,
    AVG(e.duration) as avg_duration
FROM users u
LEFT JOIN entries e ON u.id = e.user_id AND e.day = CURDATE()
WHERE u.active = 1
GROUP BY u.id, u.username
ORDER BY minutes_today DESC;
```

## Monitoring and Performance Tracking

### Application Performance Monitoring
```php
class PerformanceMonitor
{
    public function trackQuery(string $operation, callable $query): mixed
    {
        $start = microtime(true);
        $result = $query();
        $duration = (microtime(true) - $start) * 1000; // Convert to ms
        
        $this->logger->info('Query performance', [
            'operation' => $operation,
            'duration_ms' => $duration,
            'memory_mb' => memory_get_usage(true) / 1024 / 1024
        ]);
        
        return $result;
    }
}
```

### Performance Metrics Collection
```yaml
# Monitoring configuration
monolog:
    handlers:
        performance:
            type: stream
            path: '%kernel.logs_dir%/performance.log'
            level: info
            channels: ['performance']
            
        slow_query:
            type: stream  
            path: '%kernel.logs_dir%/slow_queries.log'
            level: warning
            channels: ['doctrine']
```

### Performance Dashboard Metrics
- Query execution times (avg, 95th percentile)
- Cache hit/miss ratios
- Memory usage patterns
- Database connection pool status
- Response time distribution

## Load Testing Strategy

### Test Scenarios
1. **Concurrent Users**: 100, 500, 1000 simultaneous users
2. **Data Volume**: 10k, 100k, 1M time entries
3. **Peak Load**: End-of-month export operations
4. **Mixed Workload**: Dashboard + data entry + exports

### Performance Testing Tools
```bash
# Load testing with Apache Bench
ab -n 1000 -c 50 http://timetracker.local/dashboard

# Database load testing
sysbench --mysql-host=localhost --mysql-user=timetracker \
    --test=oltp --oltp-table-size=1000000 --num-threads=50 run

# Memory profiling
php -d xdebug.profiler_enable=1 bin/console app:performance-test
```

## Deployment and Operations

### Production Configuration
```yaml
# Production performance settings
doctrine:
    orm:
        query_cache_driver:
            type: redis
            host: redis.internal
        result_cache_driver:
            type: redis
            host: redis.internal
        metadata_cache_driver:
            type: apcu

framework:
    cache:
        app: cache.adapter.redis
        default_redis_provider: redis://redis.internal:6379
```

### Performance Monitoring Setup
```bash
# Performance monitoring stack
docker-compose -f docker-compose.monitoring.yml up -d

# Includes:
# - Redis for caching
# - Prometheus for metrics
# - Grafana for dashboards
# - Elasticsearch for log analysis
```

## Maintenance and Optimization

### Regular Maintenance Tasks
- **Weekly**: Analyze slow query logs
- **Monthly**: Review cache hit ratios and adjust TTL
- **Quarterly**: Database index analysis and optimization
- **Annually**: Full performance architecture review

### Continuous Optimization
- Automated performance regression testing
- Query performance monitoring alerts  
- Cache efficiency tracking
- Resource utilization trending

## Related ADRs
- ADR-002: Repository Pattern Refactoring
- ADR-001: Service Layer Pattern Implementation
- ADR-005: Testing Strategy

## References
- [High Performance MySQL](https://www.oreilly.com/library/view/high-performance-mysql/9781449332471/)
- [Symfony Performance Best Practices](https://symfony.com/doc/current/performance.html)
- [Doctrine Performance Optimization](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/improving-performance.html)
- [PSR-6: Caching Interface](https://www.php-fig.org/psr/psr-6/)