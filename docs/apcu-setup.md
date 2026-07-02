# APCu Cache Setup

## Overview
APCu (APC User Cache) provides shared-memory caching for PHP. The
application uses it as the backend of Symfony's app cache.

## Configuration Files

### 1. Docker PHP Configuration
- **File**: `docker/php/apcu.ini`
- **Purpose**: PHP extension configuration for APCu
- **Key Settings**:
  - `apc.shm_size=32M` - Memory allocation for development
  - `apc.enable_cli=1` - Enable APCu in CLI for Symfony console commands
  - `apc.ttl=0` - No automatic expiration
  - `apc.user_entries_hint=4096` - Expected number of cache entries

### 2. Symfony Cache Configuration
- **File**: `config/packages/cache.yaml`
- **Purpose**: binds the framework app cache to `cache.adapter.apcu`
  with a stable `prefix_seed`. Services receive it via
  `$cacheItemPool: '@cache.app'` (see `config/services.yaml`).

## Production Considerations

### Memory Allocation
- **Development**: 32MB (sufficient for testing)
- **Production**: Recommend 128MB-512MB based on usage patterns
- **Monitoring**: Use `apcu_cache_info()` to monitor usage and hit rates

### Cache Invalidation
- **Deployment**: APCu cache persists across requests but not deployments
- **Manual**: `php bin/console cache:pool:clear cache.app`
- **Automatic**: Configure TTLs per cache item where volatility demands it

### Fallback Strategy
- **Graceful Degradation**: Symfony falls back cleanly when APCu is
  unavailable (e.g. CLI without `apc.enable_cli`)

## Monitoring and Debugging

- **Symfony Profiler**: shows cache operations in the web profiler
- **CLI**: `php bin/console cache:pool:list` lists configured pools
- **Statistics**: `apcu_cache_info()` for hits/misses/memory usage

## Security Considerations

- **Namespace**: keys are namespaced via `prefix_seed: timetracker`
- **No Persistence**: APCu data does not survive server restarts
- **Sensitive Data**: avoid caching passwords or raw authentication
  tokens; use short TTLs for anything auth-adjacent
