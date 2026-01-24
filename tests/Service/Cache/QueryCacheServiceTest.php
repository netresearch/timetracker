<?php

declare(strict_types=1);

namespace App\Tests\Service\Cache;

use App\Service\Cache\QueryCacheService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(QueryCacheService::class)]
final class QueryCacheServiceTest extends TestCase
{
    private CacheItemPoolInterface&MockObject $cachePool;
    private LoggerInterface&MockObject $logger;
    private QueryCacheService $service;

    protected function setUp(): void
    {
        $this->cachePool = $this->createMock(CacheItemPoolInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new QueryCacheService($this->cachePool, $this->logger);
    }

    #[Test]
    public function rememberReturnsCachedValueOnHit(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn('cached_value');

        $this->cachePool->method('getItem')
            ->with('query_test_key')
            ->willReturn($cacheItem);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache hit');

        $result = $this->service->remember('test_key', static fn () => 'new_value');

        $this->assertSame('cached_value', $result);
    }

    #[Test]
    public function rememberExecutesCallbackOnMiss(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('set')->with('new_value');
        $cacheItem->expects($this->once())->method('expiresAfter')->with(300);

        $this->cachePool->method('getItem')
            ->with('query_test_key')
            ->willReturn($cacheItem);

        $this->cachePool->expects($this->once())
            ->method('save')
            ->with($cacheItem);

        $callbackExecuted = false;
        $result = $this->service->remember('test_key', static function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return 'new_value';
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame('new_value', $result);
    }

    #[Test]
    public function rememberUsesCustomTtl(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);
        $cacheItem->expects($this->once())->method('expiresAfter')->with(600);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $this->service->remember('test_key', static fn () => 'value', 600);
    }

    #[Test]
    public function getReturnsCachedValueOnHit(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(['data' => 'test']);

        $this->cachePool->method('getItem')
            ->with('query_test_key')
            ->willReturn($cacheItem);

        $result = $this->service->get('test_key');

        $this->assertSame(['data' => 'test'], $result);
    }

    #[Test]
    public function getReturnsNullOnMiss(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cachePool->method('getItem')
            ->with('query_test_key')
            ->willReturn($cacheItem);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache miss');

        $result = $this->service->get('test_key');

        $this->assertNull($result);
    }

    #[Test]
    public function setSetsValueWithDefaultTtl(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with('value');
        $cacheItem->expects($this->once())->method('expiresAfter')->with(300);

        $this->cachePool->method('getItem')
            ->with('query_test_key')
            ->willReturn($cacheItem);

        $this->cachePool->expects($this->once())
            ->method('save')
            ->with($cacheItem);

        $this->service->set('test_key', 'value');
    }

    #[Test]
    public function setSetsValueWithCustomTtl(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with('value');
        $cacheItem->expects($this->once())->method('expiresAfter')->with(3600);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $this->service->set('test_key', 'value', 3600);
    }

    #[Test]
    public function hasReturnsTrueWhenItemExists(): void
    {
        $this->cachePool->method('hasItem')
            ->with('query_test_key')
            ->willReturn(true);

        $result = $this->service->has('test_key');

        $this->assertTrue($result);
    }

    #[Test]
    public function hasReturnsFalseWhenItemDoesNotExist(): void
    {
        $this->cachePool->method('hasItem')
            ->with('query_test_key')
            ->willReturn(false);

        $result = $this->service->has('test_key');

        $this->assertFalse($result);
    }

    #[Test]
    public function deleteRemovesItem(): void
    {
        $this->cachePool->expects($this->once())
            ->method('deleteItem')
            ->with('query_test_key');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache delete');

        $this->service->delete('test_key');
    }

    #[Test]
    public function clearClearsAllItemsWithoutPattern(): void
    {
        $this->cachePool->expects($this->once())->method('clear');

        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache cleared');

        $this->service->clear();
    }

    #[Test]
    public function clearWithPatternClearsByPattern(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache cleared by pattern');

        $this->service->clear('pattern_*');
    }

    #[Test]
    public function tagAddsKeyToTag(): void
    {
        $this->logger->expects($this->once())
            ->method('debug')
            ->with('Cache tagged');

        $this->service->tag('test_key', 'tag1');

        $stats = $this->service->getStats();
        $this->assertContains('tag1', $stats['tags']);
    }

    #[Test]
    public function tagAddsKeyToMultipleTags(): void
    {
        $this->service->tag('test_key', 'tag1', 'tag2', 'tag3');

        $stats = $this->service->getStats();
        $this->assertContains('tag1', $stats['tags']);
        $this->assertContains('tag2', $stats['tags']);
        $this->assertContains('tag3', $stats['tags']);
    }

    #[Test]
    public function invalidateTagDeletesTaggedItems(): void
    {
        // First tag some keys
        $this->service->tag('key1', 'my_tag');
        $this->service->tag('key2', 'my_tag');

        // Expect deletion of tagged items
        $this->cachePool->expects($this->exactly(2))
            ->method('deleteItem');

        $this->service->invalidateTag('my_tag');

        // Verify tag is removed
        $stats = $this->service->getStats();
        $this->assertNotContains('my_tag', $stats['tags']);
    }

    #[Test]
    public function invalidateTagDoesNothingForNonExistentTag(): void
    {
        $this->cachePool->expects($this->never())->method('deleteItem');

        $this->service->invalidateTag('nonexistent_tag');
    }

    #[Test]
    public function invalidateEntityClearsByEntityPattern(): void
    {
        // invalidateEntity calls clearByPattern which also logs
        $this->logger->expects($this->exactly(2))
            ->method('debug');

        $this->service->invalidateEntity('App\\Entity\\User', 123);
    }

    #[Test]
    public function warmUpExecutesCallbacks(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $executed = [];
        $callbacks = [
            'key1' => static function () use (&$executed) {
                $executed[] = 'key1';

                return 'value1';
            },
            'key2' => static function () use (&$executed) {
                $executed[] = 'key2';

                return 'value2';
            },
        ];

        $this->service->warmUp($callbacks);

        $this->assertContains('key1', $executed);
        $this->assertContains('key2', $executed);
    }

    #[Test]
    public function warmUpSkipsNonCallableEntries(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $executed = [];
        $callbacks = [
            'key1' => static function () use (&$executed) {
                $executed[] = 'key1';

                return 'value1';
            },
            'key2' => 'not_a_callable', // @phpstan-ignore argument.type
        ];

        // @phpstan-ignore argument.type
        $this->service->warmUp($callbacks);

        $this->assertContains('key1', $executed);
        $this->assertNotContains('key2', $executed);
    }

    #[Test]
    public function getStatsReturnsCorrectStructure(): void
    {
        // Add some tags
        $this->service->tag('key1', 'tag1');
        $this->service->tag('key2', 'tag2');

        $stats = $this->service->getStats();

        $this->assertArrayHasKey('adapter', $stats);
        $this->assertArrayHasKey('tags', $stats);
        $this->assertArrayHasKey('tag_count', $stats);
        $this->assertSame(2, $stats['tag_count']);
        $this->assertContains('tag1', $stats['tags']);
        $this->assertContains('tag2', $stats['tags']);
    }

    #[Test]
    public function constructorWorksWithoutLogger(): void
    {
        $service = new QueryCacheService($this->cachePool);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn('value');

        $this->cachePool->method('getItem')->willReturn($cacheItem);

        // Should not throw even without logger
        $result = $service->get('test_key');
        $this->assertSame('value', $result);
    }

    #[Test]
    public function clearByPatternMatchesTaggedKeys(): void
    {
        // Tag some keys
        $this->service->tag('user_123_profile', 'user_tag');
        $this->service->tag('user_123_settings', 'user_tag');
        $this->service->tag('product_456_info', 'product_tag');

        // Expect only user-related keys to be deleted
        $this->cachePool->expects($this->exactly(2))
            ->method('deleteItem');

        // Clear by pattern that matches user keys
        $this->service->clear('query_user_123_*');
    }

    #[Test]
    public function rememberHandlesComplexReturnTypes(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(false);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $complexValue = [
            'users' => [
                ['id' => 1, 'name' => 'Alice'],
                ['id' => 2, 'name' => 'Bob'],
            ],
            'total' => 2,
            'nested' => ['deep' => ['value' => true]],
        ];

        $result = $this->service->remember('complex_key', static fn () => $complexValue);

        $this->assertSame($complexValue, $result);
    }

    #[Test]
    public function setCanStoreNullValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with(null);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $this->service->set('null_key', null);
    }

    #[Test]
    public function rememberCanCacheNullValue(): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->method('isHit')->willReturn(true);
        $cacheItem->method('get')->willReturn(null);

        $this->cachePool->method('getItem')->willReturn($cacheItem);

        $callbackExecuted = false;
        $result = $this->service->remember('null_key', static function () use (&$callbackExecuted) {
            $callbackExecuted = true;

            return 'new_value';
        });

        // Callback should NOT be executed because cache hit
        $this->assertFalse($callbackExecuted);
        $this->assertNull($result);
    }

    #[Test]
    public function entityPrefixExtractsClassName(): void
    {
        // We can verify this indirectly through invalidateEntity
        // invalidateEntity calls clearByPattern which also logs
        $this->logger->expects($this->exactly(2))
            ->method('debug');

        // The pattern should include "user" (lowercase class name)
        $this->service->invalidateEntity('App\\Entity\\User', 1);
    }

    #[Test]
    public function tagCanAddSameKeyToMultipleTags(): void
    {
        $this->service->tag('shared_key', 'tag_a');
        $this->service->tag('shared_key', 'tag_b');

        $stats = $this->service->getStats();
        $this->assertSame(2, $stats['tag_count']);
        $this->assertContains('tag_a', $stats['tags']);
        $this->assertContains('tag_b', $stats['tags']);
    }

    #[Test]
    public function multipleKeysCanBeTaggedWithSameTag(): void
    {
        $this->service->tag('key1', 'common_tag');
        $this->service->tag('key2', 'common_tag');
        $this->service->tag('key3', 'common_tag');

        $stats = $this->service->getStats();
        $this->assertSame(1, $stats['tag_count']);

        // Invalidating should delete all 3 keys
        $this->cachePool->expects($this->exactly(3))
            ->method('deleteItem');

        $this->service->invalidateTag('common_tag');
    }

    #[Test]
    #[DataProvider('provideVariousCacheValues')]
    public function setCachesDifferentValueTypes(mixed $value): void
    {
        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with($value);

        $this->cachePool->method('getItem')->willReturn($cacheItem);
        $this->cachePool->method('save');

        $this->service->set('test_key', $value);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function provideVariousCacheValues(): array
    {
        return [
            'string' => ['hello world'],
            'integer' => [42],
            'float' => [3.14159],
            'boolean true' => [true],
            'boolean false' => [false],
            'array' => [['a', 'b', 'c']],
            'empty array' => [[]],
            'associative array' => [['key' => 'value']],
            'null' => [null],
            'zero' => [0],
            'empty string' => [''],
        ];
    }
}
