# Using the make:merit-achievement Command

The `make:merit-achievement` command writes a new achievement definition class.

## Usage

```bash
php artisan make:merit-achievement FirstPostAchievement
```

The class name is used as given, so include the `Achievement` suffix if you
want it. The command then asks for:

- the goal (default 1)
- any prerequisites, one key or several (all of them must be unlocked)
- the description (default "Achieve First Post")
- whether the achievement is secret

and writes:

- **File**: `app/Definitions/Achievements/FirstPostAchievement.php`, or
  wherever `merit.achievements_path` points
- **Namespace**: `merit.achievements_namespace`
- **Key**: `first_post` (the class name in snake case, with the last
  `Achievement` in it removed)
- **Name**: `First Post`

The command refuses, and exits with a failure code, when the file already
exists or another definition already uses the key. A name given with a
directory (`Posts/FirstPostAchievement`) is written flat into the definitions
directory. Discovery does find classes in subdirectories, under the matching
sub-namespace, so you can move a generated class into one by hand.

## Generated Achievement Structure

```php
<?php

namespace App\Definitions\Achievements;

use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Illuminate\Database\Eloquent\Model;

class FirstPostAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'first_post';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'First Post';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Achieve First Post';
    }

    public static function goal(): int
    {
        return 1;
    }

    public static function isSecret(): bool
    {
        return false;
    }

    public static function category(): string
    {
        return 'general';
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        // TODO: Implement your achievement logic here
        return false;
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        // TODO: Calculate current progress towards the goal
        return static::qualifiesFor($achiever) ? static::goal() : 0;
    }

    public static function prerequisite(): string|array|null
    {
        return null;
    }
}
```

Replace the two TODOs. `calculateCurrentProgress()` is what a check stores, so
for a multi-step achievement return the real count, for example
`min(static::getCount($achiever, 'posts'), static::goal())`.
