# Achievements, XP tracking, and leveling.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)
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

1. **Add the Achiever trait to your model:**

```php
use Ram64\LaravelMerit\Concerns\Achiever;

class User extends Authenticatable
{
    use Achiever;
    
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

$tracker = new AchievementTracker();

// Check if user unlocked any achievements
$tracker->processCheck($user, FirstPostAchievement::class);

// Or check all achievements
$tracker->processCheck($user);
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

### Achievement Features

- **Caching**: Automatic caching with configurable TTL for performance
- **Debug Mode**: Comprehensive logging for development and troubleshooting  
- **Secret Achievements**: Hidden achievements revealed only when unlocked
- **Categories**: Organize achievements into logical groups
- **Progress Tracking**: Track partial progress towards achievement goals
- **Prerequisites**: Chain achievements with flexible dependency system

For detailed documentation, see the `/docs` directory.

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
