# APCu Cache Setup

## Overview
APCu (APC User Cache) is a user-land caching solution that provides shared memory caching for PHP applications. This document describes the APCu implementation in the timetracker application.

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
- **Purpose**: Main cache configuration with specialized pools
- **Cache Pools**:
  - `cache.auth` - User sessions and authentication data (1 hour TTL)
  - `cache.config` - Configuration and metadata (4 hours TTL)
  - `cache.data` - Large data with filesystem fallback (24 hours TTL)
  - `cache.session` - Short-lived objects like form tokens (30 minutes TTL)

### 3. Development Override
- **File**: `config/packages/dev/cache.yaml`
- **Purpose**: Development-specific cache settings with shorter TTLs
- **Benefits**: Faster development iteration with reduced cache persistence

## Performance Benefits

### Memory-Based Caching
- **Speed**: Sub-millisecond access times for cached data
- **Efficiency**: No file system I/O for frequently accessed data
- **Scalability**: Shared memory access across all PHP processes

### Use Cases
1. **Authentication Data**: LDAP user lookups, session tokens
2. **Configuration**: Application settings, route cache
3. **Metadata**: Database schema information, translations
4. **Form Data**: CSRF tokens, temporary form state

## Production Considerations

### Memory Allocation
- **Development**: 32MB (sufficient for testing)
- **Production**: Recommend 128MB-512MB based on usage patterns
- **Monitoring**: Use `apc_cache_info()` to monitor usage and hit rates

### Cache Invalidation
- **Deployment**: APCu cache persists across requests but not deployments
- **Manual**: Use `apc_clear_cache()` or Symfony cache:clear commands
- **Automatic**: Configure TTLs based on data volatility

### Fallback Strategy
- **Filesystem**: Large or persistent data uses filesystem cache
- **Graceful Degradation**: Application works if APCu is unavailable
- **Mixed Strategy**: APCu for hot data, filesystem for cold data

## Monitoring and Debugging

### Cache Statistics
```php
// Get APCu statistics
$info = apc_cache_info();
echo "Cache hits: " . $info['num_hits'];
echo "Cache misses: " . $info['num_misses'];
echo "Memory usage: " . $info['mem_size'];
```

### Development Tools
- **Symfony Profiler**: Shows cache operations in web profiler
- **CLI Commands**: `php bin/console cache:pool:list` to see configured pools
- **Debug Cache**: Use `cache.debug` pool for development-specific caching

## Security Considerations

### Data Isolation
- **Namespace**: Configured with `prefix_seed: timetracker`
- **Process Isolation**: Cache is isolated per application
- **No Persistence**: APCu data doesn't survive server restarts

### Sensitive Data
- **Avoid**: Don't cache passwords or sensitive authentication tokens
- **Encrypt**: Consider encryption for sensitive cached data
- **TTL**: Use short TTLs for authentication-related cache entries

## Integration Examples

### Service Usage
```php
use Symfony\Contracts\Cache\CacheInterface;

class UserService
{
    public function __construct(
        private CacheInterface $authCache
    ) {}
    
    public function getUserPermissions(int $userId): array
    {
        return $this->authCache->get(
            "user_permissions_{$userId}",
            function() use ($userId) {
                return $this->loadPermissionsFromLdap($userId);
            }
        );
    }
}
```

### Configuration Injection
```yaml
# services.yaml
services:
    App\Service\UserService:
        arguments:
            $authCache: '@cache.auth'
```

## Troubleshooting

### Common Issues
1. **Permission Errors**: Ensure APCu is enabled in both CLI and web contexts
2. **Memory Exhaustion**: Increase `apc.shm_size` if cache is full
3. **CLI Issues**: Verify `apc.enable_cli=1` is set

### Verification Commands
```bash
# Check APCu installation
docker-compose exec app-dev php -m | grep apcu

# Test cache functionality
docker-compose exec app-dev php -r "var_dump(apc_enabled());"

# Clear cache
docker-compose exec app-dev php bin/console cache:clear
```