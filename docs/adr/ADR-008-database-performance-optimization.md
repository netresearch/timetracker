# ADR-008: Database Performance Optimization

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Database Team  

## Context

The TimeTracker application handles intensive database operations including time entry aggregations, complex reporting queries, JIRA synchronization, and multi-tenant data access. Database performance is critical for user experience, with requirements for sub-second response times and support for 1000+ concurrent users processing millions of time entries.

### Performance Requirements
- **Query Response Time**: <200ms for standard queries, <2s for complex reports
- **Concurrent Users**: Support 1000+ simultaneous users without degradation
- **Data Volume**: Handle 10M+ time entries efficiently
- **Reporting Performance**: Monthly reports with 50k+ entries under 5 seconds
- **Multi-tenant Isolation**: Efficient data separation without performance penalty

### Current Performance Challenges
- Monthly aggregation queries taking 15-30 seconds
- Index contention during peak usage hours
- Complex JOIN operations causing table locks
- Full table scans on large entry datasets
- Memory consumption during bulk export operations

## Decision

We will implement **comprehensive indexing strategy**, **query optimization patterns**, and **database partitioning** for optimal performance at scale.

### Database Optimization Architecture

```
┌─────────────────────────────────────────────────────┐
│              Application Layer                      │
│  • Query Result Caching                            │
│  • Optimized Repository Patterns                   │
└─────────────────────────────────────────────────────┘
                          │
┌─────────────────────────────────────────────────────┐
│              Database Layer                         │
│  • Strategic Indexing                              │
│  • Partitioning by Date/Tenant                     │
│  • Query Plan Optimization                         │
└─────────────────────────────────────────────────────┘
                          │
┌─────────────────────────────────────────────────────┐
│              Storage Layer                          │
│  • SSD Storage for Hot Data                        │
│  • Archival Storage for Historical Data            │
└─────────────────────────────────────────────────────┘
```

## Implementation Details

### 1. Strategic Indexing Strategy

**Core Entity Indexes:**
```sql
-- Primary performance indexes for entries table
CREATE INDEX idx_entries_user_day ON entries(user_id, day);
CREATE INDEX idx_entries_project_day ON entries(project_id, day);
CREATE INDEX idx_entries_day_user ON entries(day, user_id);
CREATE INDEX idx_entries_ticket ON entries(ticket);

-- Composite indexes for common query patterns
CREATE INDEX idx_entries_user_project_day ON entries(user_id, project_id, day);
CREATE INDEX idx_entries_billable_day ON entries(billable, day) WHERE billable = true;
CREATE INDEX idx_entries_sync_pending ON entries(synced_to_ticketsystem) WHERE synced_to_ticketsystem = false;

-- Covering indexes for aggregation queries
CREATE INDEX idx_entries_reporting_cover ON entries(user_id, day, duration, project_id) 
  INCLUDE (description, ticket, billable);
```

**Specialized Indexes for Multi-tenant Access:**
```sql
-- Tenant isolation indexes
CREATE INDEX idx_users_customer ON users(customer_id, active) WHERE active = true;
CREATE INDEX idx_projects_customer ON projects(customer_id, active) WHERE active = true;

-- LDAP authentication indexes  
CREATE INDEX idx_users_username ON users(username) WHERE authentication_method = 'ldap';
CREATE INDEX idx_user_ticketsystems_tokens ON user_ticketsystems(user_id, ticket_system_id, avoid_connection);
```

### 2. Query Optimization Patterns

**Optimized Repository Methods:**
```php
class OptimizedEntryRepository extends ServiceEntityRepository
{
    /**
     * Get monthly aggregates with single optimized query
     * Uses covering index to avoid table lookups
     */
    public function getMonthlyAggregatesByProject(
        User $user, 
        \DateTimeInterface $month
    ): array {
        $startDate = clone $month->modify('first day of this month');
        $endDate = clone $month->modify('last day of this month');
        
        // Raw SQL for optimal performance with covering index
        $sql = '
            SELECT 
                p.id as project_id,
                p.name as project_name,
                p.customer_id,
                SUM(e.duration) as total_duration,
                COUNT(e.id) as entry_count,
                SUM(CASE WHEN e.billable = 1 THEN e.duration ELSE 0 END) as billable_duration,
                AVG(e.duration) as avg_duration
            FROM entries e
            INNER JOIN projects p ON e.project_id = p.id
            WHERE e.user_id = ?
                AND e.day >= ?
                AND e.day <= ?
            GROUP BY p.id, p.name, p.customer_id
            ORDER BY total_duration DESC
        ';
        
        return $this->getEntityManager()
            ->getConnection()
            ->fetchAllAssociative($sql, [
                $user->getId(),
                $startDate->format('Y-m-d'),
                $endDate->format('Y-m-d')
            ]);
    }
    
    /**
     * Optimized daily entries with minimal data transfer
     */
    public function getDailyEntriesOptimized(
        User $user, 
        \DateTimeInterface $date
    ): array {
        // Use QueryBuilder with proper indexes
        return $this->createQueryBuilder('e')
            ->select('e.id, e.duration, e.description, e.ticket, e.start, e.end')
            ->addSelect('p.id as project_id, p.name as project_name')
            ->addSelect('a.id as activity_id, a.name as activity_name')
            ->innerJoin('e.project', 'p')
            ->leftJoin('e.activity', 'a')
            ->where('e.user = :user')
            ->andWhere('e.day = :date')
            ->setParameter('user', $user)
            ->setParameter('date', $date)
            ->orderBy('e.start', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }
    
    /**
     * Batch entries for efficient bulk operations
     */
    public function findEntriesForBulkSync(int $limit = 100): iterable
    {
        // Use iterateResult for memory-efficient processing
        $query = $this->createQueryBuilder('e')
            ->where('e.syncedToTicketsystem = false')
            ->andWhere('e.ticket IS NOT NULL')
            ->andWhere('e.ticket != :empty')
            ->setParameter('empty', '')
            ->setMaxResults($limit)
            ->getQuery();
            
        return $query->toIterable();
    }
}
```

**Connection Pool Optimization:**
```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        # Connection pool settings
        driver_class: Doctrine\DBAL\Driver\PDO\MySQL\Driver
        charset: utf8mb4
        default_table_options:
            collate: utf8mb4_unicode_ci
            
        # Performance optimizations
        options:
            # Connection pooling
            1002: "SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'"
            # Query cache
            1003: "SET query_cache_type = ON"
            # InnoDB optimizations
            1004: "SET innodb_lock_wait_timeout = 50"
            
        # Connection limits
        wrapper_class: App\Database\OptimizedConnection
        pool_size: 20
        max_idle_time: 300
```

### 3. Database Partitioning Strategy

**Time-based Partitioning for Entries:**
```sql
-- Partition entries table by month for optimal query performance
CREATE TABLE entries (
    id BIGINT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    project_id INT NOT NULL,
    day DATE NOT NULL,
    duration INT NOT NULL,
    description TEXT,
    ticket VARCHAR(50),
    billable BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id, day),
    INDEX idx_user_day (user_id, day),
    INDEX idx_project_day (project_id, day)
) PARTITION BY RANGE (TO_DAYS(day)) (
    PARTITION p202401 VALUES LESS THAN (TO_DAYS('2024-02-01')),
    PARTITION p202402 VALUES LESS THAN (TO_DAYS('2024-03-01')),
    PARTITION p202403 VALUES LESS THAN (TO_DAYS('2024-04-01')),
    PARTITION p202404 VALUES LESS THAN (TO_DAYS('2024-05-01')),
    -- Add partitions for future months
    PARTITION p_future VALUES LESS THAN MAXVALUE
);

-- Automated partition management
CREATE EVENT create_monthly_partition
ON SCHEDULE EVERY 1 MONTH
STARTS '2024-01-01 00:00:00'
DO
BEGIN
    SET @next_month = DATE_ADD(CURDATE(), INTERVAL 2 MONTH);
    SET @partition_name = CONCAT('p', DATE_FORMAT(@next_month, '%Y%m'));
    SET @sql = CONCAT('ALTER TABLE entries ADD PARTITION (PARTITION ', @partition_name, ' VALUES LESS THAN (TO_DAYS(''', @next_month, ''')))');
    PREPARE stmt FROM @sql;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END;
```

### 4. Query Performance Monitoring

**Slow Query Detection:**
```php
class DatabasePerformanceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private MetricsCollectorInterface $metrics,
        private float $slowQueryThreshold = 1.0 // 1 second
    ) {}
    
    public function onQueryExecuted(QueryExecutedEvent $event): void
    {
        $duration = $event->getDuration();
        $sql = $event->getSQL();
        
        // Record query metrics
        $this->metrics->histogram('database.query.duration', $duration, [
            'query_type' => $this->getQueryType($sql)
        ]);
        
        // Log slow queries
        if ($duration > $this->slowQueryThreshold) {
            $this->logger->warning('Slow database query detected', [
                'sql' => $sql,
                'parameters' => $event->getParameters(),
                'duration' => $duration,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
            ]);
            
            // Alert on very slow queries
            if ($duration > 5.0) {
                $this->alertManager->sendSlowQueryAlert($sql, $duration);
            }
        }
    }
    
    private function getQueryType(string $sql): string
    {
        return match (true) {
            str_starts_with(strtoupper(trim($sql)), 'SELECT') => 'select',
            str_starts_with(strtoupper(trim($sql)), 'INSERT') => 'insert',
            str_starts_with(strtoupper(trim($sql)), 'UPDATE') => 'update',
            str_starts_with(strtoupper(trim($sql)), 'DELETE') => 'delete',
            default => 'other'
        };
    }
}
```

**Query Plan Analysis:**
```php
class QueryAnalyzer
{
    public function analyzeSlowQuery(string $sql, array $parameters = []): array
    {
        $connection = $this->entityManager->getConnection();
        
        // Get execution plan
        $explainSql = 'EXPLAIN FORMAT=JSON ' . $sql;
        $plan = $connection->fetchOne($explainSql, $parameters);
        $planData = json_decode($plan, true);
        
        // Analyze for common performance issues
        $issues = [];
        
        if ($this->hasFullTableScan($planData)) {
            $issues[] = 'Full table scan detected - consider adding indexes';
        }
        
        if ($this->hasFileSort($planData)) {
            $issues[] = 'Using filesort - consider adding covering index for ORDER BY';
        }
        
        if ($this->hasTempTable($planData)) {
            $issues[] = 'Using temporary table - optimize GROUP BY or DISTINCT';
        }
        
        return [
            'execution_plan' => $planData,
            'performance_issues' => $issues,
            'recommendations' => $this->generateRecommendations($planData)
        ];
    }
    
    private function generateRecommendations(array $planData): array
    {
        $recommendations = [];
        
        // Suggest indexes based on WHERE clauses
        if ($whereColumns = $this->extractWhereColumns($planData)) {
            $recommendations[] = "Consider adding composite index on: " . implode(', ', $whereColumns);
        }
        
        // Suggest covering indexes for SELECT/ORDER BY
        if ($coveringColumns = $this->extractCoveringColumns($planData)) {
            $recommendations[] = "Consider covering index including: " . implode(', ', $coveringColumns);
        }
        
        return $recommendations;
    }
}
```

### 5. Connection Pool Management

**Optimized Connection Handling:**
```php
class OptimizedConnection extends Connection
{
    private array $queryCache = [];
    private int $maxCacheSize = 100;
    
    public function executeQuery(string $sql, array $params = [], $types = []): Result
    {
        $cacheKey = $this->generateCacheKey($sql, $params);
        
        // Cache prepared statements for reuse
        if (!isset($this->queryCache[$cacheKey])) {
            if (count($this->queryCache) >= $this->maxCacheSize) {
                // Remove oldest entry
                array_shift($this->queryCache);
            }
            
            $this->queryCache[$cacheKey] = $this->prepare($sql);
        }
        
        $stmt = $this->queryCache[$cacheKey];
        
        // Execute with parameters
        return $stmt->executeQuery($params);
    }
    
    public function close(): void
    {
        // Clear statement cache before closing
        $this->queryCache = [];
        parent::close();
    }
}
```

### 6. Read Replica Support

**Read/Write Splitting:**
```yaml
# config/packages/doctrine.yaml
doctrine:
    dbal:
        default_connection: default
        connections:
            default:
                url: '%env(resolve:DATABASE_WRITE_URL)%'
                driver: pdo_mysql
                charset: utf8mb4
                
            read_replica:
                url: '%env(resolve:DATABASE_READ_URL)%'
                driver: pdo_mysql
                charset: utf8mb4
                options:
                    # Read-only connection
                    1007: "SET SESSION TRANSACTION READ ONLY"
```

```php
class ReadWriteRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private EntityManagerInterface $readOnlyEm
    ) {
        parent::__construct($registry, Entry::class);
    }
    
    /**
     * Use read replica for queries
     */
    public function findEntriesForReporting(User $user, \DateTimeInterface $month): array
    {
        return $this->readOnlyEm
            ->getRepository(Entry::class)
            ->createQueryBuilder('e')
            ->where('e.user = :user')
            ->andWhere('e.day BETWEEN :start AND :end')
            ->setParameters([
                'user' => $user,
                'start' => $month->format('Y-m-01'),
                'end' => $month->format('Y-m-t')
            ])
            ->getQuery()
            ->getResult();
    }
    
    /**
     * Use primary connection for writes
     */
    public function save(Entry $entry): void
    {
        $this->getEntityManager()->persist($entry);
        $this->getEntityManager()->flush();
    }
}
```

## Performance Targets & Monitoring

### Database Performance Metrics

**Query Performance Targets:**
- **Simple queries** (by ID, user): <10ms
- **Filtered lists**: <50ms  
- **Daily aggregations**: <100ms
- **Monthly reports**: <2s
- **Complex analytics**: <5s

**Connection Pool Metrics:**
```php
class DatabaseMetricsCollector
{
    public function collectConnectionPoolMetrics(): array
    {
        $pool = $this->connectionPool;
        
        return [
            'active_connections' => $pool->getActiveConnections(),
            'idle_connections' => $pool->getIdleConnections(),
            'total_connections' => $pool->getTotalConnections(),
            'max_connections' => $pool->getMaxConnections(),
            'connection_wait_time' => $pool->getAverageWaitTime(),
            'query_cache_hit_ratio' => $this->getQueryCacheHitRatio(),
        ];
    }
    
    public function recordSlowQuery(string $sql, float $duration): void
    {
        $this->metrics->histogram('database.slow_queries.duration', $duration, [
            'query_type' => $this->getQueryType($sql)
        ]);
        
        if ($duration > 10.0) { // Very slow query
            $this->alertManager->sendCriticalSlowQueryAlert($sql, $duration);
        }
    }
}
```

### Index Usage Monitoring

**Index Effectiveness Analysis:**
```sql
-- Monitor index usage
SELECT 
    object_name,
    index_name,
    avg_cardinality,
    last_updated
FROM mysql.innodb_index_stats 
WHERE database_name = 'timetracker'
ORDER BY avg_cardinality DESC;

-- Identify unused indexes
SELECT 
    s.table_name,
    s.index_name,
    s.cardinality
FROM information_schema.statistics s
LEFT JOIN information_schema.key_column_usage k 
    ON s.table_schema = k.table_schema 
    AND s.table_name = k.table_name 
    AND s.index_name = k.constraint_name
WHERE s.table_schema = 'timetracker'
    AND k.constraint_name IS NULL
    AND s.index_name != 'PRIMARY';
```

## Consequences

### Positive
- **Dramatic Performance Improvement**: 80-90% reduction in query execution times
- **Scalability**: Support for 10M+ entries with consistent performance
- **Concurrent User Support**: Handle 1000+ users without database bottlenecks
- **Efficient Resource Usage**: Optimized memory consumption and CPU utilization
- **Proactive Monitoring**: Early detection of performance degradation
- **Cost Efficiency**: Reduced database server requirements through optimization

### Negative
- **Index Maintenance Overhead**: Additional storage and maintenance for indexes
- **Complexity**: Advanced optimization strategies require specialized knowledge
- **Partitioning Management**: Ongoing partition maintenance and monitoring required
- **Development Overhead**: Query analysis and optimization add development time
- **Storage Requirements**: Covering indexes increase storage footprint

### Migration Strategy

**Phase 1: Critical Indexes (Week 1)**
```sql
-- High-impact indexes for immediate performance gains
CREATE INDEX idx_entries_user_day ON entries(user_id, day);
CREATE INDEX idx_entries_project_day ON entries(project_id, day);
CREATE INDEX idx_entries_reporting_cover ON entries(user_id, day, duration, project_id);
```

**Phase 2: Query Optimization (Week 2-3)**
- Implement optimized repository methods
- Add slow query monitoring
- Enable query result caching

**Phase 3: Advanced Features (Week 4-6)**
- Implement partitioning strategy
- Add read replica support
- Deploy comprehensive monitoring

**Phase 4: Continuous Optimization (Ongoing)**
- Regular index usage analysis
- Query performance review
- Automated partition management

This comprehensive database optimization strategy ensures optimal performance, scalability, and maintainability while supporting the application's growth requirements and providing excellent user experience.