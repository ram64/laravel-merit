# Using the make:merit-achievement Command

The `make:merit-achievement` command creates new achievement classes with proper stubs and validation.

## Usage

```bash
php artisan make:merit-achievement FirstPost
```

This will create:
- **File**: `app/Achievements/FirstPostAchievement.php`
- **Key**: `first_post`
- **Name**: `First Post`

## Examples

```bash
# Creates ProlificWriterAchievement with key 'prolific_writer'
php artisan make:merit-achievement ProlificWriter

# Creates SocialButterflyAchievement with key 'social_butterfly'  
php artisan make:merit-achievement SocialButterfly

# The command automatically handles naming conventions
php artisan make:merit-achievement EarlyBirdAchievement
# Creates EarlyBirdAchievement with key 'early_bird'
```

## Features

- **Unique Key Validation**: Prevents duplicate achievement keys
- **Automatic Naming**: Converts class names to readable achievement names
- **Configurable Paths**: Uses your `config/merit.php` settings
- **Helpful Stubs**: Includes TODO comments and examples

## Generated Achievement Structure

```php
<?php

namespace App\Achievements;

use Ram64\LaravelMerit\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

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
        return 'Achieve First Post';
    }

    public static function goal(): int
    {
        return 1;
    }

    public static function category(): string
    {
        return 'general';
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        // TODO: Implement your achievement logic here
        return static::hasMinimumCount($achiever, 'posts', static::goal());
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        // TODO: Calculate current progress towards the goal
        return min(static::getCount($achiever, 'posts'), static::goal());
    }

    public static function prerequisite(): ?string
    {
        return null;
    }
}
```
