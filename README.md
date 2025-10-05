# Achievements, XP tracking, and leveling.

[![Latest Version on Packagist](https://img.shields    public static function name(?Model $achiever = null): string
    {
        return 'First Post';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Create your first post on the platform.';
    }

    // Example of dynamic content (optional):
    // public static function name(?Model $achiever = null): string
    // {
    //     if ($achiever) {
    //         $progress = static::getProgress($achiever);
    //         $remaining = static::getRemainingProgress($achiever);
    //         if ($remaining > 0) {
    //             return "First Post ({$progress}/{$remaining} remaining)";
    //         }
    //     }
    //     return 'First Post';
    // }gist/v/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)

Laravel Merit brings gamification to your Laravel apps with clean, code-first achievements, XP tracking, and leveling. Define achievements in config, track progress with traits, and reward your users with milestones and badges.


## Support us
To do

## Installation

You can install the package via composer:

```bash
composer require ram64/laravel-merit
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="laravel-merit-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="laravel-merit-config"
```

This is the contents of the published config file:

```php
return [

    'achiever_model' => env('MERIT_ACHIEVER_MODEL', App\Models\User::class),
    'achievements_table' => env('MERIT_ACHIEVEMENTS_TABLE', 'achiever_achievements'),
    'cache_ttl' => 10,
    'queue_events' => env('MERIT_QUEUE_EVENTS', false),
];

```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="laravel-merit-views"
```

## Usage

### Basic Setup

1. **Add the CanAchieve trait to your model:**

```php
use Ram64\LaravelMerit\Concerns\CanAchieve;

class User extends Authenticatable
{
    use CanAchieve;
    
    // Your model code...
}
```

2. **Create Achievement Definitions:**

Use the artisan command to create new achievements:

```php
php artisan make:merit-achievement FirstPost --category=posts
```

This creates a new achievement class:

```php
<?php

namespace App\Definitions\Achievements\Posts;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;

class FirstPostAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'first_post';
    }

    public static function name(): string
    {
        return 'First Post';
    }

    public static function description(): string
    {
        return 'Create your very first post';
    }

    public static function goal(): int
    {
        return 1;
    }

    public static function category(): string
    {
        return 'posts';
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

3. **Track Achievement Progress:**

```php
use Ram64\LaravelMerit\Services\AchievementTracker;

// Check all achievements for a user
AchievementTracker::check($user);

// Get achievement definition by key
$definition = AchievementTracker::getDefinition('first_post');

// Get definition from an AchieverAchievement model
$achievement = $user->achievements()->where('achievement_key', 'first_post')->first();
$definition = AchievementTracker::getDefinitionFromAchievement($achievement);

// Get all achievement keys
$keys = AchievementTracker::getAchievementKeys();

// Get achievement display data
$display = AchievementTracker::getAchievementDisplay($user, 'first_post');
```

### Achievement Prerequisites

Laravel Merit supports flexible prerequisite definitions. Achievements can require other achievements to be completed first:

```php
public static function prerequisite(): string|array|null
{
    // No prerequisites
    return null;
    
    // Single string key prerequisite
    return 'first_post';
    
    // Single class prerequisite
    return FirstPostAchievement::class;
    
    // Multiple mixed prerequisites
    return [
        'first_post',                    // String key
        FifthPostAchievement::class,     // Class reference
    ];
}
```

### Experience & Leveling System

Laravel Merit includes a comprehensive experience and leveling system:

```php
use Ram64\LaravelMerit\Concerns\Experiencer;

class User extends Authenticatable
{
    use Experiencer;
}

```php
use Ram64\LaravelMerit\Concerns\CanExperience;

class User extends Authenticatable
{
    use CanExperience;
}

// Award experience
$user->gainExperience(100, 'Completed quest');
```

// Check level and progress
$level = $user->getLevel();
$progress = $user->getExperienceProgress();
```

**Features:**
- Flexible level calculation (static thresholds or dynamic formulas)
- Experience logging with optional enable/disable
- Events for experience changes and level-ups
- Caching for performance
- Polymorphic support for any model

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [ram64](https://github.com/ram64)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
