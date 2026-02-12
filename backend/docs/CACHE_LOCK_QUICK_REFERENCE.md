# Cache Lock Pattern - Quick Reference

## Basic Usage

```php
use App\Services\CacheLockService;

class MyService
{
    protected CacheLockService $cacheLock;

    public function __construct(CacheLockService $cacheLock)
    {
        $this->cacheLock = $cacheLock;
    }

    public function getExpensiveData(int $id): array
    {
        return $this->cacheLock->remember(
            "expensive_data_{$id}",
            300, // 5 minutes TTL
            fn() => $this->fetchFromDatabase($id)
        );
    }
}
```

## When to Use

✅ **Use cache lock pattern when**:
- Cache regeneration is expensive (>100ms)
- High traffic endpoints (>10 req/s)
- Dashboard queries with complex aggregations
- Report generation
- API responses with heavy computation

❌ **Don't use cache lock pattern when**:
- Simple queries (<10ms)
- Low traffic endpoints
- Data changes frequently (cache TTL < 10s)
- Single-user operations

## Patterns

### 1. Standard Lock Pattern

```php
$data = $cacheLock->remember($key, $ttl, $callback);
```

**Use for**: Most caching scenarios

### 2. Stale-While-Revalidate

```php
$data = $cacheLock->rememberWithStale($key, $ttl, $callback, $staleTtl);
```

**Use for**: User-facing APIs where stale data is acceptable

### 3. Custom Lock Timeout

```php
$data = $cacheLock->remember($key, $ttl, $callback, 30); // 30s lock
```

**Use for**: Slow operations (>10s)

## Configuration

| Parameter | Default | Description |
|-----------|---------|-------------|
| `LOCK_TIMEOUT` | 10s | How long to hold lock |
| `MAX_RETRIES` | 3 | Retry attempts |
| `RETRY_DELAY_MS` | 100ms | Base delay (exponential) |

## Common Scenarios

### Dashboard Stats

```php
public function getDashboardStats(int $schoolId): array
{
    return $this->cacheLock->remember(
        "dashboard_{$schoolId}",
        300, // 5 min
        fn() => $this->calculateStats($schoolId)
    );
}
```

### Monthly Reports

```php
public function getMonthlyReport(int $schoolId, int $month): array
{
    return $this->cacheLock->remember(
        "monthly_report_{$schoolId}_{$month}",
        1800, // 30 min
        fn() => $this->generateReport($schoolId, $month),
        30 // 30s lock timeout for slow reports
    );
}
```

### Real-time Data with Stale Support

```php
public function getRealtimeStats(int $schoolId): array
{
    return $this->cacheLock->rememberWithStale(
        "realtime_{$schoolId}",
        60, // 1 min fresh
        fn() => $this->fetchRealtimeData($schoolId),
        30 // 30s stale
    );
}
```

## Cache Invalidation

```php
// Invalidate both fresh and stale cache
Cache::forget($cacheKey);
Cache::forget("{$cacheKey}:stale");
```

## Testing

```bash
# Run cache stampede tests
php artisan test --filter CacheStampedeTest
```

## Monitoring

Check logs for:
- `Cache lock: Regenerating cache` - Normal regeneration
- `Cache lock: Retrying after backoff` - Lock contention
- `Cache lock: Max retries exceeded` - ⚠️ Potential issue

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Frequent retries | Increase lock timeout or optimize callback |
| Stale data | Reduce stale TTL or invalidate on updates |
| High memory | Reduce cache TTL or cache size |

## Performance

**Before**: 100 concurrent requests = 100 DB queries  
**After**: 100 concurrent requests = 1 DB query  
**Improvement**: 99% reduction in database load
