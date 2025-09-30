# Caching in Laravel Merit

Laravel Merit includes comprehensive caching to optimize performance and reduce database/filesystem queries. All caching respects the `cache_ttl` configuration setting.

## Configuration

### Environment Variables (.env)
```env
# Cache TTL in minutes (default: 10)
MERIT_CACHE_TTL=15

# Optional: Use specific cache store
CACHE_DRIVER=redis
```

### Config File (config/merit.php)
```php
'cache_ttl' => env('MERIT_CACHE_TTL', 10), // minutes
```

## What Gets Cached

### 1. Achievement Discovery
- **What**: List of all discovered achievement classes
- **Key**: `merit:achievements:discovery:[hash]`
- **Duration**: Full cache TTL
- **Purpose**: Avoids filesystem scanning on every request

### 2. Achievement Display Data
- **What**: Formatted achievement data for users (name, progress, etc.)
- **Key**: `merit:display:[achiever_class]:[achiever_id]:[achievement_key]`
- **Duration**: Full TTL for unlocked, half TTL for in-progress
- **Purpose**: Reduces database queries for achievement status

### 3. Achievement Records
- **What**: Individual `AchieverAchievement` database records
- **Key**: `merit:record:[achiever_class]:[achiever_id]:[achievement_key]`
- **Duration**: Full cache TTL
- **Purpose**: Caches frequently accessed progress records

### 4. User Achievement Collections
- **What**: User-specific achievement collections
- **Key**: `merit:user:[achiever_class]:[achiever_id]`
- **Duration**: Full cache TTL
- **Purpose**: Reduces queries for user achievement lists

## Cache Invalidation

Caches are automatically cleared when:

### Progress Changes
```php
// These automatically clear relevant caches
AchievementTracker::check($user);
AchievementTracker::increment($user, 'achievement_key', 1);
AchievementTracker::award($user, 'achievement_key');
```

### Manual Cache Clearing
```php
// Clear all caches for a specific user
AchievementTracker::clearUserCache($user);

// Clear achievement discovery cache
AchievementTracker::clearDiscoveryCache();

// Clear caches via CanAchieve trait
$user->clearAchievementCaches();
```

## Performance Benefits

### Before Caching
```
Request 1: Scan filesystem (10ms) + Query DB (5ms) = 15ms
Request 2: Scan filesystem (10ms) + Query DB (5ms) = 15ms  
Request 3: Scan filesystem (10ms) + Query DB (5ms) = 15ms
Total: 45ms for 3 requests
```

### With Caching
```
Request 1: Scan filesystem (10ms) + Query DB (5ms) + Cache (1ms) = 16ms
Request 2: Cache hit (1ms) = 1ms
Request 3: Cache hit (1ms) = 1ms
Total: 18ms for 3 requests (60% improvement)
```

## Usage Examples

### Basic Cached Operations
```php
use Ram64\LaravelMerit\Services\AchievementTracker;

// This will use cached data when available
$achievements = AchievementTracker::getVisibleAchievements($user);
$display = AchievementTracker::getAchievementDisplay($user, 'first_post');

// These will invalidate relevant caches
AchievementTracker::check($user);
$user->clearAchievementCaches();
```

### Using CanAchieve Trait Methods
```php
// Cached methods on your User model
$visibleAchievements = $user->getVisibleAchievements();
$achievementData = $user->getCachedAchievementDisplay('first_post');

// This clears all caches for the user
$user->clearAchievementCaches();
```

### Monitoring Cache Performance
```php
// Check if caching is helping (in a service provider or command)
$cacheHits = Cache::getRedis()->hget('laravel_cache:merit:*', 'hits');
$cacheMisses = Cache::getRedis()->hget('laravel_cache:merit:*', 'misses');

$hitRatio = $cacheHits / ($cacheHits + $cacheMisses);
echo "Cache hit ratio: " . ($hitRatio * 100) . "%";
```

## Cache Keys Reference

All cache keys follow consistent patterns:

```
merit:achievements:discovery:[hash]              # Discovery cache
merit:display:[class]:[id]:[key]                # Display data
merit:record:[class]:[id]:[key]                 # Achievement records  
merit:user:[class]:[id]                         # User collections
```

## Advanced Configuration

### Different TTLs for Different Data Types
```php
// In a custom service provider
public function boot()
{
    // Override cache durations
    config(['merit.cache_ttl' => 30]); // 30 minutes for discovery
    config(['merit.display_cache_ttl' => 5]); // 5 minutes for display data
}
```

### Custom Cache Tags (if using Redis/Memcached)
```php
use Illuminate\Support\Facades\Cache;

// Tag caches for easier clearing
Cache::tags(['merit', 'achievements'])->put($key, $data, $ttl);

// Clear all Merit caches
Cache::tags(['merit'])->flush();
```

## Best Practices

### ✅ Do's
- Let the system handle cache invalidation automatically
- Use appropriate cache TTL based on your app's needs
- Monitor cache hit rates in production
- Use `clearUserCache()` after bulk operations

### ❌ Don'ts  
- Don't manually manage individual cache keys
- Don't set extremely long TTLs for progress data
- Don't forget to clear caches in custom achievement logic
- Don't disable caching in high-traffic applications

## Troubleshooting

### Cache Not Working
```bash
# Check if cache is configured
php artisan config:cache
php artisan cache:clear

# Verify cache driver
php artisan tinker
>>> Cache::getStore()
```

### Stale Data Issues
```php
// Force cache refresh
AchievementTracker::clearDiscoveryCache();
$user->clearAchievementCaches();

// Or clear everything
php artisan cache:clear
```

### Debug Cache Performance
Enable Merit debug mode to see cache operations:
```env
MERIT_DEBUG=true
```

Look for logs like:
```
[Merit] Loaded achievements from cache {"count":15}
[Merit] Retrieved achievement display from cache {"achievement":"first_post"}
```

Caching dramatically improves performance while maintaining data consistency! 🚀
