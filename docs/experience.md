# Experience System

Laravel Merit includes a comprehensive experience and leveling system that allows any model to gain experience points and level up.

## Setup

### 1. Add the CanExperience trait to your model

```php
use Ram64\LaravelMerit\Concerns\CanExperience;

class User extends Authenticatable
{
    use CanExperience;

    // Your model code...
}
```

### 2. Run the migrations

```bash
php artisan migrate
```

This creates two tables:
- `experiencers` - Stores experience and level data for models
- `experience_logs` - Logs all experience changes (optional, can be disabled)

## Basic Usage

### Award Experience

```php
// Using the trait directly
$user->gainExperience(100, 'Completed tutorial');

// Using the ExperienceManager service
ExperienceManager::awardExperience($user, 100, 'Completed tutorial');
```

### Remove Experience

```php
// Using the trait directly
$user->loseExperience(50, 'Penalty for rule violation');

// Using the ExperienceManager service
ExperienceManager::deductExperience($user, 50, 'Penalty for rule violation');
```

### Get Experience Data

```php
$experience = $user->getExperience(); // Current XP points
$level = $user->getLevel(); // Current level
$progress = $user->getExperienceProgress(); // Progress to next level

// Or get all cached data at once
$data = $user->getCachedExperienceData();
```

### Set Experience or Level Directly

```php
// Set experience to a specific amount
$user->setExperience(500, 'Admin adjustment');

// Set level directly (experience adjusts automatically)
$user->setLevel(5, 'Promoted by admin');

// Level decreases are controlled by config (see below)
$user->setLevel(3, 'Level correction'); // Only works if allow_level_decrease is true
```

### Remove Experience

```php
// Using the trait directly
$user->loseExperience(50, 'Penalty for rule violation');

// Using the ExperienceManager service
ExperienceManager::deductExperience($user, 50, 'Penalty for rule violation');

## Level Calculation

Laravel Merit supports two level calculation methods: static thresholds and dynamic formulas.

### Static Thresholds (Default)

Configure specific experience requirements for each level:

```php
// config/merit.php
'experience' => [
    'level_calculation' => 'static',
    'level_thresholds' => [
        1 => 0,
        2 => 100,
        3 => 250,
        4 => 500,
        5 => 1000,
        // Add more levels...
    ],
],
```

### Dynamic Formulas

Use mathematical formulas for level calculation:

```php
// config/merit.php
'experience' => [
    'level_calculation' => 'dynamic',
    'level_formula' => '50 * $level * $level', // Quadratic growth
],
```

Available variables: `$level`

**Pre-calculating Requirements:**
```php
// Generate lookup table for levels 1-100
$requirements = ExperienceManager::precalculateLevelRequirements(100);
// Result: [1 => 50, 2 => 200, 3 => 450, 4 => 800, ...]
```

## Events

The experience system fires three types of events:

### ExperienceGained

Fired when experience is awarded:

```php
use Ram64\LaravelMerit\Events\ExperienceGained;

Event::listen(ExperienceGained::class, function ($event) {
    Log::info("{$event->experiencer->name} gained {$event->amount} XP: {$event->reason}");
});
```

### ExperienceLost

Fired when experience is deducted:

```php
use Ram64\LaravelMerit\Events\ExperienceLost;

Event::listen(ExperienceLost::class, function ($event) {
    Log::info("{$event->experiencer->name} lost {$event->amount} XP: {$event->reason}");
});
```

### LevelledUp

Fired when a level up occurs:

```php
use Ram64\LaravelMerit\Events\LevelledUp;

Event::listen(LevelledUp::class, function ($event) {
    Log::info("{$event->experiencer->name} levelled up from {$event->oldLevel} to {$event->newLevel}!");
});
```

## Experience Logging

Experience changes can be logged for auditing and analytics. This feature can be enabled/disabled in config:

```php
// config/merit.php
'experience' => [
    'logging_enabled' => true, // Set to false to disable logging
],
```

### Optimized Log Structure

The logs use an optimized structure that eliminates redundant data:

```php
// Before/After values (experience_change can be calculated)
'experience_before' => 100,
'experience_after' => 150,  // +50 XP gained

// Level tracking (event_type can be inferred)
'level_before' => 2,
'level_after' => 3,  // Level up occurred
```

### Querying Logs

```php
// Get recent experience logs for a user
$logs = $user->experienceLogs()->limit(10)->get();

// Using scopes (inferred from data)
$gainedLogs = ExperienceLog::gained()->get();      // experience_after > experience_before
$lostLogs = ExperienceLog::lost()->get();          // experience_after < experience_before
$levelUps = ExperienceLog::levelUps()->get();      // level_after > level_before

// Check log types
foreach ($logs as $log) {
    if ($log->isGain()) {
        // Experience was gained
    } elseif ($log->isLoss()) {
        // Experience was lost
    } elseif ($log->isLevelUp()) {
        // Level up occurred
    }
}

// Using the service
$logs = ExperienceManager::getExperienceLogs($user, 10);
```

## Configuration Options

### Experience Table Names

```php
'experience' => [
    'table' => 'experiencers',           // Main experience table
    'logs_table' => 'experience_logs',   // Experience logs table
],
```

### Caching

```php
'experience' => [
    'cache_ttl' => 10, // Cache TTL in minutes
],
```

### Event Queueing

```php
'experience' => [
    'queue_events' => false, // Set to true to queue events
    'queue_connection' => null,
],
```

### Level Decrease Permissions

```php
'experience' => [
    'allow_level_decrease' => false, // Set to true to allow level decreases via setLevel()
],
```

## Advanced Usage

### Bulk Experience Awards

```php
$awards = [
    ['experiencer' => $user1, 'amount' => 100, 'reason' => 'Daily login'],
    ['experiencer' => $user2, 'amount' => 150, 'reason' => 'Quest completion'],
];

$results = ExperienceManager::bulkAwardExperience($awards);
```

### Statistics

```php
$stats = ExperienceManager::getStatistics();

// Returns:
// - total_experiencers
// - total_experience_awarded
// - total_experience_lost
// - total_level_ups
// - average_level
// - highest_level
```

### Level Requirements

```php
// Pre-calculate level requirements for dynamic formulas
$requirements = ExperienceManager::precalculateLevelRequirements(50);
// Returns: [1 => 50, 2 => 200, 3 => 450, ...]

// Get cached level requirements (recommended for performance)
$requirements = ExperienceManager::getCachedLevelRequirements(50);

// Get experience required for a specific level
$expForLevel5 = ExperienceManager::getExperienceForLevel(5);
```

### Custom Level Calculations

You can create custom level calculation logic by extending the `Experiencer` model:

```php
class CustomExperiencer extends \Ram64\LaravelMerit\Models\Experiencer
{
    public function calculateLevel(int $experience): int
    {
        // Custom logic here
        return parent::calculateLevel($experience);
    }
}
```

## Rules & Constraints

- **Experience never goes below 0**: Attempts to deduct more XP than available will set experience to 0
- **Levels never decrease naturally**: Levels are permanent unless `allow_level_decrease` is enabled in config
- **Administrative level changes**: Configure `allow_level_decrease => true` to enable level decreases via `setLevel()`
- **Config changes**: If level thresholds change, existing users keep their current levels
- **Thread safety**: Experience operations are atomic to prevent race conditions

## Integration with Achievements

Experience and achievements work seamlessly together:

```php
// Award experience when unlocking achievements
$user->gainExperience(50, 'Achievement unlocked: First Post');

// Check achievements based on experience levels
class ExperiencedUserAchievement extends AchievementDefinition
{
    public static function qualifiesFor(Model $achiever): bool
    {
        return $achiever->getLevel() >= 5; // Requires level 5
    }
}
```

## Performance Considerations

- Experience data is cached to improve performance
- Use `getCachedExperienceData()` for multiple reads
- Clear caches after bulk operations: `$user->clearExperienceCaches()`
- Experience logging can be disabled for high-traffic applications
