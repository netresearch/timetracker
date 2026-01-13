<?php

declare(strict_types=1);

namespace App\Service\Cache;

use Exception;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

use function count;
use function is_callable;
use function sprintf;

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
     *
     * @var array<string, array<string>>
     */
    private array $tags = [];

    public function __construct(
        private readonly CacheItemPoolInterface $cacheItemPool,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Gets cached value or executes callback and caches result.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @throws \Psr\Cache\InvalidArgumentException When cache key is invalid
     * @throws Exception                           When callback execution fails
     *
     * @return T
     */
    public function remember(string $key, callable $callback, int $ttl = self::DEFAULT_TTL): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cacheItemPool->getItem($cacheKey);

        if ($item->isHit()) {
            $this->logger?->debug('Cache hit');

            return $item->get();
        }

        $this->logger?->debug('Cache miss');

        $value = $callback();

        $item->set($value);
        $item->expiresAfter($ttl);

        $this->cacheItemPool->save($item);

        $this->logger?->debug('Cache set');

        return $value;
    }

    /**
     * Gets cached value.
     */
    public function get(string $key): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cacheItemPool->getItem($cacheKey);

        if ($item->isHit()) {
            $this->logger?->debug('Cache hit');

            return $item->get();
        }

        $this->logger?->debug('Cache miss');

        return null;
    }

    /**
     * Sets cached value.
     */
    public function set(string $key, mixed $value, int $ttl = self::DEFAULT_TTL): void
    {
        $cacheKey = $this->getCacheKey($key);
        $item = $this->cacheItemPool->getItem($cacheKey);

        $item->set($value);
        $item->expiresAfter($ttl);

        $this->cacheItemPool->save($item);

        $this->logger?->debug('Cache set');
    }

    /**
     * Checks if key exists in cache.
     */
    public function has(string $key): bool
    {
        $cacheKey = $this->getCacheKey($key);

        return $this->cacheItemPool->hasItem($cacheKey);
    }

    /**
     * Deletes cached value.
     */
    public function delete(string $key): void
    {
        $cacheKey = $this->getCacheKey($key);
        $this->cacheItemPool->deleteItem($cacheKey);

        $this->logger?->debug('Cache delete');
    }

    /**
     * Clears all cached values with optional pattern matching.
     */
    public function clear(?string $pattern = null): void
    {
        if (null === $pattern) {
            $this->cacheItemPool->clear();
            $this->logger?->debug('Cache cleared');

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
            if (! isset($this->tags[$tag])) {
                $this->tags[$tag] = [];
            }

            $this->tags[$tag][] = $cacheKey;
        }

        $this->logger?->debug('Cache tagged');
    }

    /**
     * Invalidates all cache entries with given tag.
     */
    public function invalidateTag(string $tag): void
    {
        if (! isset($this->tags[$tag])) {
            return;
        }

        foreach ($this->tags[$tag] as $cacheKey) {
            $this->cacheItemPool->deleteItem($cacheKey);
        }

        unset($this->tags[$tag]);

        $this->logger?->debug('Tag invalidated');
    }

    /**
     * Invalidates cache for specific entity.
     */
    public function invalidateEntity(string $entityClass, int $entityId): void
    {
        $pattern = sprintf('%s_%s_%d_*', self::DEFAULT_PREFIX, $this->getEntityPrefix($entityClass), $entityId);
        $this->clearByPattern($pattern);

        $this->logger?->debug('Entity cache invalidated');
    }

    /**
     * Warms up cache by executing callbacks.
     *
     * @param array<string, callable> $callbacks
     */
    public function warmUp(array $callbacks): void
    {
        foreach ($callbacks as $key => $callback) {
            if (is_callable($callback)) {
                $this->remember($key, $callback);
            }
        }

        $this->logger?->debug('Cache warmed up');
    }

    /**
     * Gets cache statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        // This depends on the cache adapter implementation
        // Some adapters provide statistics, others don't

        return [
            'adapter' => $this->cacheItemPool::class,
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
        foreach ($this->tags as $tag) {
            foreach ($tag as $key) {
                if (fnmatch($pattern, $key)) {
                    $this->cacheItemPool->deleteItem($key);
                }
            }
        }

        $this->logger?->debug('Cache cleared by pattern');
    }
}
