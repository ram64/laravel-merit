# Achievement Prerequisites

Laravel Merit now supports flexible prerequisite definitions for achievements. You can specify that certain achievements must be unlocked before others become available.

## Prerequisite Types

### 1. No Prerequisites

For achievements that can be unlocked immediately:

```php
public static function prerequisite(): string|array|null
{
    return null; // No requirements
}
```

### 2. Single String Key Prerequisite

Specify a single achievement that must be unlocked first using its key:

```php
public static function prerequisite(): string|array|null
{
    return 'first_post'; // Must unlock first_post achievement
}
```

### 3. Single Class Prerequisite

Reference another achievement class directly:

```php
public static function prerequisite(): string|array|null
{
    return FirstPostAchievement::class; // Must unlock FirstPostAchievement
}
```

### 4. Multiple Prerequisites (Mixed)

Specify multiple prerequisites using a mix of strings and classes:

```php
public static function prerequisite(): string|array|null
{
    return [
        'first_post',                    // String key
        FifthPostAchievement::class,     // Class reference
        TenthPostAchievement::class,     // Another class reference
    ];
}
```

## How It Works

### Prerequisite Checking

The `canStart()` method automatically checks all prerequisites:

```php
// Check if user can start this achievement
if (PostMasterAchievement::canStart($user)) {
    // All prerequisites are met
}
```

### Key Extraction

The system automatically extracts achievement keys from class references:

- **String keys**: Used directly (`'first_post'` → `'first_post'`)
- **Class references**: Key extracted using `::key()` method (`FirstPostAchievement::class` → `'first_post'`)

### Validation

The system validates that:

1. Class references must exist and be subclasses of `BaseAchievementDefinition`
2. Class references must have a `key()` method
3. All prerequisite achievements must be unlocked for the user

## Example Achievement Structure

```php
<?php

namespace App\Definitions\Achievements\Posts;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;

class PostMasterAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'post_master';
    }

    public static function name(): string
    {
        return 'Post Master';
    }

    public static function description(): string
    {
        return 'Master the art of posting with 100 posts';
    }

    public static function goal(): int
    {
        return 100;
    }

    public static function category(): string
    {
        return 'posts';
    }

    // Mixed prerequisites
    public static function prerequisite(): string|array|null
    {
        return [
            'first_post',                    // Must complete first post
            FifthPostAchievement::class,     // Must complete 5th post
            TenthPostAchievement::class,     // Must complete 10th post
        ];
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return static::getCount($achiever, 'posts') >= static::goal();
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        return min(static::getCount($achiever, 'posts'), static::goal());
    }
}
```

## Usage in Achievement Tracker

The `AchievementTracker` automatically respects prerequisites:

```php
use Ram64\LaravelMerit\Services\AchievementTracker;

$tracker = new AchievementTracker();

// Only processes achievements whose prerequisites are met
$tracker->processCheck($user, PostMasterAchievement::class);
```

## Benefits

1. **Type Safety**: Class references provide IDE autocompletion and refactoring support
2. **Flexibility**: Mix string keys and class references as needed
3. **Validation**: Automatic validation of class references and prerequisites
4. **Performance**: Efficient prerequisite checking with database queries
5. **Maintainability**: Easy to track achievement dependencies

## Migration from String-Only Prerequisites

If you have existing achievements with string prerequisites, they continue to work unchanged:

```php
// This still works
public static function prerequisite(): string|array|null
{
    return 'first_post';
}

// You can gradually migrate to class references
public static function prerequisite(): string|array|null
{
    return FirstPostAchievement::class;
}
```
