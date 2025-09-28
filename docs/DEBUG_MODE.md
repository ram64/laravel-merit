# Debug Mode for Laravel Merit

Laravel Merit includes comprehensive debug logging to help you troubleshoot achievement tracking and understand what's happening under the hood.

## Enabling Debug Mode

### Via Environment Variables (.env)
```env
# Enable debug mode
MERIT_DEBUG=true

# Optional: Set custom log channel (default: single)
MERIT_DEBUG_CHANNEL=merit

# Optional: Set log level (default: debug)
MERIT_DEBUG_LEVEL=info
```

### Via Config File (config/merit.php)
```php
'debug' => [
    'enabled' => true,
    'log_channel' => 'merit', // or 'single', 'daily', etc.
    'log_level' => 'debug',   // debug, info, warning, error
],
```

## What Gets Logged

When debug mode is enabled, Laravel Merit logs detailed information about:

### Achievement Checking
- When achievement checks start
- Queue vs synchronous processing
- Individual achievement evaluations
- Prerequisites checking
- Progress calculations
- Achievement completions and unlocks

### Manual Operations
- Manual achievement awards
- Progress increments
- Achievement discoveries

### Discovery Process
- Achievement file scanning
- Class registration
- Invalid or skipped files

## Example Debug Output

```
[2025-01-01 10:00:00] local.DEBUG: [Merit] Starting achievement check {"achiever_id":1,"achiever_type":"App\\Models\\User","queue_enabled":false}
[2025-01-01 10:00:00] local.DEBUG: [Merit] Processing achievement check {"achiever_id":1,"achiever_type":"App\\Models\\User","total_achievements":5}
[2025-01-01 10:00:00] local.DEBUG: [Merit] Checking achievement: first_post {"achievement_class":"App\\Achievements\\FirstPostAchievement","achiever_id":1}
[2025-01-01 10:00:00] local.DEBUG: [Merit] Current progress calculated {"achievement":"first_post","progress":1,"goal":1}
[2025-01-01 10:00:00] local.DEBUG: [Merit] Achievement completed and unlocked: first_post
[2025-01-01 10:00:00] local.DEBUG: [Merit] Achievement check processing completed
```

## Setting Up Custom Log Channel

Create a dedicated log channel for Merit debugging in `config/logging.php`:

```php
'channels' => [
    // ... existing channels

    'merit' => [
        'driver' => 'single',
        'path' => storage_path('logs/merit.log'),
        'level' => 'debug',
        'replace_placeholders' => true,
    ],
],
```

Then set in your `.env`:
```env
MERIT_DEBUG_CHANNEL=merit
```

## Log Levels

Choose appropriate log levels based on your needs:

- **debug**: Most verbose, includes all operations
- **info**: Important events like completions and unlocks  
- **warning**: Issues like missing prerequisites
- **error**: Actual errors and exceptions

## Production Considerations

⚠️ **Important**: Debug mode logs sensitive information and can be verbose. 

**Recommendations:**
- Only enable in development/staging environments
- Use higher log levels (info/warning) in production if needed
- Rotate logs regularly if enabled in production
- Consider performance impact with high-volume achievement checking

## Troubleshooting Common Issues

### Achievement Not Triggering
Enable debug mode and look for:
```
[Merit] Prerequisites not met for achievement: achievement_key
[Merit] Achievement already unlocked: achievement_key  
[Merit] Current progress calculated {"progress":0,"goal":5}
```

### Discovery Issues
Look for discovery logs:
```
[Merit] Achievement path does not exist {"path":"/app/Achievements"}
[Merit] Skipping class (abstract or invalid base) {"class":"App\\Achievements\\BaseAchievement"}
[Merit] Class does not exist {"class":"App\\Achievements\\InvalidAchievement"}
```

### Performance Issues
Monitor logs for:
- Large numbers of achievement checks
- Frequent database queries
- Queue vs synchronous processing patterns

## Programmatic Debug Access

You can also check debug status in your code:

```php
if (config('merit.debug.enabled')) {
    // Debug mode is active
    // Maybe show additional UI elements
}
```

Debug mode is an invaluable tool for understanding and optimizing your achievement system! 🔍
