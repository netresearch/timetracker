# ADR-005: Caching Strategy

**Status:** Accepted  
**Date:** 2024-09-15  
**Deciders:** Architecture Team, Performance Team  

## Context

The TimeTracker application handles intensive database operations including complex time aggregations, LDAP authentication, JIRA synchronization, and large-scale reporting. A comprehensive caching strategy is essential to achieve performance targets while maintaining data consistency and user experience.

### Performance Requirements
- **API Response Time**: <200ms for standard endpoints, <2s for complex reports
- **Dashboard Loading**: <500ms for daily/weekly views, <5s for monthly reports
- **Concurrent Users**: Support 1000+ simultaneous users without degradation
- **Database Load**: Reduce query load by 60-80% through effective caching
- **Memory Usage**: Efficient memory utilization across application servers

### Current Performance Challenges
- Monthly reports with 50k+ entries taking 15-30 seconds
- Repeated LDAP group lookups adding 200-500ms per request
- JIRA ticket validation causing API rate limit issues
- Dashboard aggregations causing database locks during peak hours

## Decision

We will implement a **multi-layer caching strategy** with **APCu (application cache)**, **Redis (distributed cache)**, and **HTTP caching** for optimal performance across all system components.

### Cache Layer Architecture

```
┌─────────────────────────────────────────────────────┐
│                 HTTP Cache                          │
│  (Reverse Proxy, CDN, Browser Cache)               │
└─────────────────────────────────────────────────────┘
                          │
┌─────────────────────────────────────────────────────┐
│              Application Cache                      │
│  (APCu - In-Process, Ultra-Fast)                  │
└─────────────────────────────────────────────────────┘
                          │
┌─────────────────────────────────────────────────────┐
│             Distributed Cache                       │
│  (Redis - Shared Across Servers)                   │
└─────────────────────────────────────────────────────┘
                          │
┌─────────────────────────────────────────────────────┐
│              Query Cache                            │
│  (Doctrine Result Cache, Database Query Cache)     │
└─────────────────────────────────────────────────────┘
```

## Implementation Details

### 1. Application Cache (APCu) - Level 1

**Purpose**: Ultra-fast in-process caching for frequently accessed small data
**TTL**: 5-15 minutes
**Use Cases**: User sessions, configuration, small lookup tables

```php
class APCuCacheService
{
    public function getUserRoles(int $userId): array
    {
        $cacheKey = "user_roles_{$userId}";
        
        if (apcu_exists($cacheKey)) {
            return apcu_fetch($cacheKey);
        }
        
        $roles = $this->userService->getUserRoles($userId);
        apcu_store($cacheKey, $roles, 900); // 15 minutes
        
        return $roles;
    }
    
    public function getSystemConfiguration(): array
    {
        $cacheKey = 'system_config';
        
        if (apcu_exists($cacheKey)) {
            return apcu_fetch($cacheKey);
        }
        
        $config = $this->configService->getAll();
        apcu_store($cacheKey, $config, 3600); // 1 hour
        
        return $config;
    }
}
```

### 2. Distributed Cache (Redis) - Level 2

**Purpose**: Shared cache across application servers for medium-to-large datasets
**TTL**: 15 minutes to 24 hours based on data volatility
**Use Cases**: Aggregated reports, LDAP data, JIRA ticket information

```php
class RedisCacheService
{
    private RedisInterface $redis;
    
    public function getMonthlyReport(int $userId, string $month): ?array
    {
        $cacheKey = "monthly_report_{$userId}_{$month}";
        
        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            return json_decode($cached, true);
        }
        
        $report = $this->reportService->generateMonthlyReport($userId, $month);
        
        // Cache for 1 hour, expire at midnight to ensure fresh daily data
        $ttl = min(3600, $this->getSecondsUntilMidnight());
        $this->redis->setex($cacheKey, $ttl, json_encode($report));
        
        return $report;
    }
    
    public function cacheLdapGroups(string $username, array $groups): void
    {
        $cacheKey = "ldap_groups_{$username}";
        
        // LDAP group memberships change rarely, cache for 1 hour
        $this->redis->setex($cacheKey, 3600, json_encode($groups));
    }
    
    public function cacheJiraTicketValidation(string $ticketKey, bool $isValid): void
    {
        $cacheKey = "jira_ticket_valid_{$ticketKey}";
        
        // Ticket existence rarely changes, cache for 30 minutes
        $this->redis->setex($cacheKey, 1800, $isValid ? '1' : '0');
    }
}
```

### 3. Query Cache (Doctrine) - Level 3

**Purpose**: Cache database query results at the ORM level
**TTL**: 5-60 minutes based on data mutation frequency
**Use Cases**: Complex aggregations, reports, lookup tables

```yaml
# config/packages/doctrine.yaml
doctrine:
    orm:
        query_cache_driver:
            type: pool
            pool: cache.app
        result_cache_driver:
            type: pool
            pool: cache.app
        metadata_cache_driver:
            type: pool  
            pool: cache.app

# config/packages/cache.yaml
framework:
    cache:
        pools:
            cache.app:
                adapter: cache.adapter.redis
                default_lifetime: 3600
                provider: 'redis://localhost:6379'
```

```php
class OptimizedEntryRepository extends ServiceEntityRepository
{
    public function getUserDailyAggregates(int $userId, \DateTimeInterface $date): array
    {
        $query = $this->createQueryBuilder('e')
            ->select('
                SUM(e.duration) as totalDuration,
                COUNT(e.id) as entryCount,
                p.name as projectName
            ')
            ->join('e.project', 'p')
            ->where('e.user = :userId')
            ->andWhere('e.day = :date')
            ->groupBy('p.id')
            ->setParameter('userId', $userId)
            ->setParameter('date', $date)
            ->getQuery();
        
        // Enable result caching for 30 minutes
        $query->enableResultCache(1800, "user_daily_agg_{$userId}_{$date->format('Y-m-d')}");
        
        return $query->getResult();
    }
    
    public function getProjectStatistics(): array
    {
        $query = $this->getEntityManager()
            ->createQuery('
                SELECT 
                    p.id,
                    p.name,
                    COUNT(e.id) as entryCount,
                    SUM(e.duration) as totalDuration,
                    AVG(e.duration) as avgDuration
                FROM App\Entity\Entry e
                JOIN e.project p
                WHERE e.day >= :startDate
                GROUP BY p.id
                ORDER BY totalDuration DESC
            ')
            ->setParameter('startDate', new \DateTime('-30 days'));
        
        // Cache project statistics for 1 hour
        $query->enableResultCache(3600, 'project_statistics_30d');
        
        return $query->getResult();
    }
}
```

### 4. HTTP Cache - Level 4

**Purpose**: Cache HTTP responses at reverse proxy/CDN level
**TTL**: 1-60 minutes for API endpoints, 24 hours for static content

```php
class CacheableResponseService
{
    public function createCacheableResponse(
        array $data, 
        int $maxAge = 300, 
        array $tags = []
    ): Response {
        $response = new JsonResponse($data);
        
        // Set cache headers
        $response->setMaxAge($maxAge);
        $response->setSharedMaxAge($maxAge);
        $response->setPublic();
        
        // Add cache tags for smart invalidation
        if ($tags) {
            $response->headers->set('Cache-Tags', implode(',', $tags));
        }
        
        // Add ETag for conditional requests
        $etag = md5(json_encode($data));
        $response->setEtag($etag);
        
        return $response;
    }
}

#[Route('/api/entries/daily/{date}', methods: ['GET'])]
public function getDailyEntries(string $date, Request $request): Response
{
    $userId = $this->getUser()->getId();
    $dateObj = new \DateTime($date);
    
    // Check if-none-match header for 304 responses
    $etag = md5("daily_entries_{$userId}_{$date}");
    if ($request->headers->get('If-None-Match') === $etag) {
        return new Response('', 304);
    }
    
    $entries = $this->entryService->getDailyEntries($userId, $dateObj);
    
    return $this->cacheService->createCacheableResponse(
        $entries,
        300, // 5 minutes
        ["user_{$userId}", "entries", "daily"]
    );
}
```

## Cache Invalidation Strategy

### Smart Invalidation by Tags
```php
class CacheInvalidationService
{
    public function invalidateUserCache(int $userId): void
    {
        // Invalidate APCu cache
        apcu_delete_by_prefix("user_{$userId}_");
        
        // Invalidate Redis cache with patterns
        $pattern = "*user_{$userId}*";
        $keys = $this->redis->keys($pattern);
        if ($keys) {
            $this->redis->del($keys);
        }
        
        // Invalidate HTTP cache tags
        $this->httpCacheInvalidator->invalidateTags(["user_{$userId}"]);
    }
    
    public function invalidateProjectCache(int $projectId): void
    {
        $this->redis->del([
            "project_statistics_30d",
            "project_details_{$projectId}",
        ]);
        
        $this->httpCacheInvalidator->invalidateTags(["project_{$projectId}"]);
    }
    
    // Event-driven invalidation
    #[AsEventListener(event: EntryUpdatedEvent::class)]
    public function onEntryUpdated(EntryUpdatedEvent $event): void
    {
        $entry = $event->getEntry();
        $userId = $entry->getUser()->getId();
        $projectId = $entry->getProject()->getId();
        $date = $entry->getDay()->format('Y-m-d');
        
        // Invalidate specific caches affected by this entry
        $this->invalidateUserCache($userId);
        $this->invalidateProjectCache($projectId);
        
        // Invalidate date-specific caches
        $this->redis->del([
            "monthly_report_{$userId}_{$date}",
            "daily_aggregates_{$userId}_{$date}",
        ]);
    }
}
```

### Hierarchical Cache Warm-up
```php
class CacheWarmupService
{
    public function warmupDashboardCache(int $userId): void
    {
        $today = new \DateTime();
        $thisMonth = $today->format('Y-m');
        
        // Pre-populate common dashboard queries
        $this->cacheService->getMonthlyReport($userId, $thisMonth);
        $this->cacheService->getUserDailyAggregates($userId, $today);
        $this->cacheService->getRecentProjects($userId);
        
        $this->logger->info("Dashboard cache warmed up for user {$userId}");
    }
    
    #[Schedule('0 6 * * *')] // Daily at 6 AM
    public function warmupSystemCache(): void
    {
        // Warm up frequently accessed system data
        $this->cacheService->getSystemConfiguration();
        $this->cacheService->getActiveProjects();
        $this->cacheService->getProjectStatistics();
        
        $this->logger->info('System cache warmed up successfully');
    }
}
```

## Performance Targets & Monitoring

### Cache Hit Rate Targets
- **APCu Cache**: >90% hit rate for user sessions and config
- **Redis Cache**: >80% hit rate for reports and aggregations
- **Query Cache**: >70% hit rate for complex database queries
- **HTTP Cache**: >85% hit rate for API endpoints

### Performance Monitoring
```php
class CacheMetricsCollector
{
    public function recordCacheHit(string $layer, string $key): void
    {
        $this->metrics->increment('cache.hits', [
            'layer' => $layer,
            'key_type' => $this->getCacheKeyType($key)
        ]);
    }
    
    public function recordCacheMiss(string $layer, string $key): void
    {
        $this->metrics->increment('cache.misses', [
            'layer' => $layer,
            'key_type' => $this->getCacheKeyType($key)
        ]);
    }
    
    public function recordCacheGenerationTime(string $key, float $duration): void
    {
        $this->metrics->histogram('cache.generation_time', $duration, [
            'key_type' => $this->getCacheKeyType($key)
        ]);
        
        if ($duration > 1.0) {
            $this->logger->warning('Slow cache generation', [
                'key' => $key,
                'duration' => $duration
            ]);
        }
    }
}
```

## Consequences

### Positive
- **Dramatic Performance Improvement**: 60-80% reduction in database queries
- **Scalability**: Support for 1000+ concurrent users with minimal hardware
- **User Experience**: Sub-200ms response times for common operations
- **Cost Efficiency**: Reduced database server load and infrastructure costs
- **Resilience**: Graceful degradation when cache layers are unavailable

### Negative
- **Complexity**: Multi-layer caching adds operational complexity
- **Memory Usage**: Additional memory requirements for cache layers
- **Data Consistency**: Potential for stale data if invalidation fails
- **Dependencies**: Redis dependency for distributed caching
- **Debugging**: Cache-related issues can be challenging to troubleshoot

### Cache Configuration
```yaml
# config/packages/cache.yaml
framework:
    cache:
        app: cache.adapter.redis
        pools:
            # Fast in-memory cache for session data
            cache.apcu:
                adapter: cache.adapter.apcu
                default_lifetime: 900
                
            # Distributed cache for shared data
            cache.redis:
                adapter: cache.adapter.redis
                provider: 'redis://localhost:6379'
                default_lifetime: 3600
                
            # Long-term storage for reports
            cache.reports:
                adapter: cache.adapter.redis
                provider: 'redis://localhost:6379'
                default_lifetime: 86400
```

### Migration Strategy
1. **Phase 1**: Implement APCu caching for user sessions and configuration
2. **Phase 2**: Add Redis distributed cache for reports and aggregations
3. **Phase 3**: Enable Doctrine query caching for expensive database operations
4. **Phase 4**: Implement HTTP caching with proper invalidation
5. **Phase 5**: Add comprehensive monitoring and alerting
6. **Phase 6**: Performance optimization based on production metrics

This multi-layer caching strategy provides optimal performance while maintaining data consistency and system reliability across all application components.