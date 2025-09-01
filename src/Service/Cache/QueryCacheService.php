<?php

declare(strict_types=1);

namespace App\Service\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing query result caching.
 * Provides centralized cache management with automatic invalidation.
 */
class QueryCacheService
{
    private const string DEFAULT_PREFIX = 'query_';
    private const int DEFAULT_TTL = 300; // 5 minutes
    
    /**
     * Cache invalidation tags for group invalidation.
     */
    private array $tags = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Gets cached value or executes callback and caches result.
     * 
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cache->getItem($cacheKey);
        
        if ($item->isHit()) {
            $this->log('Cache hit', ['key' => $cacheKey]);
            return $item->get();
        }
        
        $this->log('Cache miss', ['key' => $cacheKey]);
        
        $value = $callback();
        
        $item->set($value);
        $item->expiresAfter($ttl);
        
        $this->cache->save($item);
        
        $this->log('Cache set', ['key' => $cacheKey, 'ttl' => $ttl]);
        
        return $value;
    }

    /**
     * Gets cached value.
     */
    public function get(string $key): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cache->getItem($cacheKey);
        
        if ($item->isHit()) {
            $this->log('Cache hit', ['key' => $cacheKey]);
            return $item->get();
        }
        
        $this->log('Cache miss', ['key' => $cacheKey]);
        
        return null;
    }

    /**
     * Sets cached value.
     */
    public function set(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): void
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cache->getItem($cacheKey);
        
        $item->set($value);
        $item->expiresAfter($ttl);
        
        $this->cache->save($item);
        
        $this->log('Cache set', ['key' => $cacheKey, 'ttl' => $ttl]);
    }

    /**
     * Checks if key exists in cache.
     */
    public function has(string $key): bool
    {
        $cacheKey = $this->getCacheKey($key);
        return $this->cache->hasItem($cacheKey);
    }

    /**
     * Deletes cached value.
     */
    public function delete(string $key): void
    {
        $cacheKey = $this->getCacheKey($key);
        $this->cache->deleteItem($cacheKey);
        
        $this->log('Cache delete', ['key' => $cacheKey]);
    }

    /**
     * Clears all cached values with optional pattern matching.
     */
    public function clear(?string $pattern = null): void
    {
        if (null === $pattern) {
            $this->cache->clear();
            $this->log('Cache cleared');
            return;
        }
        
        // Pattern-based clearing (if supported by cache adapter)
        $this->clearByPattern($pattern);
    }

    /**
     * Tags a cache key for group invalidation.
     */
    public function tag(string $key, string ...$tags): void
    {
        $cacheKey = $this->getCacheKey($key);
        
        foreach ($tags as $tag) {
            if (!isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }
            
            $this->tags[$tag][] = $cacheKey;
        }
        
        $this->log('Cache tagged', ['key' => $cacheKey, 'tags' => $tags]);
    }

    /**
     * Invalidates all cache entries with given tag.
     */
    public function invalidateTag(string $tag): void
    {
        if (!isset($this->tags[$tag])) {
            return;
        }
        
        foreach ($this->tags[$tag] as $cacheKey) {
            $this->cache->deleteItem($cacheKey);
        }
        
        unset($this->tags[$tag]);
        
        $this->log('Tag invalidated', ['tag' => $tag]);
    }

    /**
     * Invalidates cache for specific entity.
     */
    public function invalidateEntity(string $entityClass, int $entityId): void
    {
        $pattern = sprintf('%s_%s_%d_*', self::DEFAULT_PREFIX, $this->getEntityPrefix($entityClass), $entityId);
        $this->clearByPattern($pattern);
        
        $this->log('Entity cache invalidated', ['entity' => $entityClass, 'id' => $entityId]);
    }

    /**
     * Warms up cache by executing callbacks.
     */
    public function warmUp(array $callbacks): void
    {
        foreach ($callbacks as $key => $callback) {
            if (is_callable($callback)) {
                $this->remember($key, $callback);
            }
        }
        
        $this->log('Cache warmed up', ['keys' => array_keys($callbacks)]);
    }

    /**
     * Gets cache statistics.
     */
    public function getStats(): array
    {
        // This depends on the cache adapter implementation
        // Some adapters provide statistics, others don't
        
        return [
            'adapter' => get_class($this->cache),
            'tags' => array_keys($this->tags),
            'tag_count' => count($this->tags),
        ];
    }

    /**
     * Generates cache key with prefix.
     */
    private function getCacheKey(string $key): string
    {
        return self::DEFAULT_PREFIX . $key;
    }

    /**
     * Gets entity prefix for cache keys.
     */
    private function getEntityPrefix(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);
        return strtolower(end($parts));
    }

    /**
     * Clears cache by pattern (implementation depends on adapter).
     */
    private function clearByPattern(string $pattern): void
    {
        // This is adapter-specific
        // For Redis/Memcached adapters, we could use SCAN/DELETE commands
        // For filesystem adapter, we could iterate through files
        
        // Fallback: iterate through known keys
        foreach ($this->tags as $tag => $keys) {
            foreach ($keys as $key) {
                if (fnmatch($pattern, $key)) {
                    $this->cache->deleteItem($key);
                }
            }
        }
        
        $this->log('Cache cleared by pattern', ['pattern' => $pattern]);
    }

    /**
     * Logs cache operations.
     */
    private function log(string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->debug('[QueryCache] ' . $message, $context);
        }
    }
}