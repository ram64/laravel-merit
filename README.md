# Laravel Merit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)

Achievements, XP and levelling for Laravel. Each achievement is a PHP class in
your application; the database stores only each achiever's progress. Adding an
achievement needs no migration, and deleting one leaves its stored rows
orphaned rather than broken.

Requires PHP 8.3+ and Laravel 11.45+, 12.25+ or 13.

## Installation

```bash
composer require ram64/laravel-merit
```

Publish and run the migrations. They create three tables:
`achiever_achievements`, `experiencers` and `experience_logs`.

```bash
php artisan vendor:publish --tag="merit-migrations"
php artisan migrate
```

Optionally publish the config file to `config/merit.php`:

```bash
php artisan vendor:publish --tag="merit-config"
```

## Configuration

Every option can be set from the environment.

| Key | Env | Default | Purpose |
| --- | --- | --- | --- |
| `achievements_namespace` | `MERIT_ACHIEVEMENTS_NAMESPACE` | `App\Definitions\Achievements\` | Namespace of your definitions |
| `achievements_path` | `MERIT_ACHIEVEMENTS_PATH` | `app/Definitions/Achievements` | Directory scanned for definitions (relative to `app/`) |
| `achievements_table` | `MERIT_ACHIEVEMENTS_TABLE` | `achiever_achievements` | Progress table |
| `cache_ttl` | `MERIT_CACHE_TTL` | `10` | Minutes to cache discovery, records and display text |
| `queue_achievements` | `MERIT_QUEUE_EVENTS` | `false` | Run `check()` on a queued job instead of inline |
| `queue_connection` | `MERIT_QUEUE_CONNECTION` | `null` | Connection for that job |
| `queue_name` | `MERIT_QUEUE_NAME` | `null` | Queue for that job |
| `debug.enabled` | `MERIT_DEBUG` | `false` | Log every step of a check (see `debug.log_channel`) |
| `experience.*` | | | Tables, logging, and the level ladder; see [Experience and levels](#experience-and-levels) |

The migrations read the table names from the config, so set any custom names
before running them.

## Achievements

### Add the trait

```php
use Ram64\LaravelMerit\Concerns\CanAchieve;

class User extends Authenticatable
{
    use CanAchieve;
}
```

### Define an achievement

```bash
php artisan make:merit-achievement FirstPostAchievement
```

The command asks for the goal, any prerequisites, a description and whether
the achievement is secret, then writes
`app/Definitions/Achievements/FirstPostAchievement.php` with the key
`first_post`. Fill in how progress is measured:

```php
namespace App\Definitions\Achievements;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;

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
        return 'Create your first post.';
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

Extend `AchievementDefinition`, not `BaseAchievementDefinition`: the tracker
relies on the prerequisite support the former adds.

### Track progress

```php
use Ram64\LaravelMerit\Services\AchievementTracker;

// Re-evaluate every achievement for this user. Runs inline, or on a queued
// job when merit.queue_achievements is true.
AchievementTracker::check($user);

// Always inline.
AchievementTracker::processCheck($user);

// Award outright, or move progress by hand.
AchievementTracker::award($user, 'first_post');
AchievementTracker::increment($user, 'prolific_poster', 1);
```

A check asks each definition for `calculateCurrentProgress()` and stores the
result. A progress row is created only once an achievement has some progress,
so an achievement nobody has started has no row at all. Reaching the goal
completes and unlocks the achievement in the same pass, and an unlocked
achievement stays unlocked whatever its progress does afterwards.

### Prerequisites

```php
public static function prerequisite(): string|array|null
{
    return 'first_post';                    // a key
    return FirstPostAchievement::class;     // or a class
    return ['first_post', 'first_comment']; // ALL of these
}
```

An array means every prerequisite must be unlocked; there is no "any of".
Achievements are evaluated in dependency order, so a chain can complete in a
single check. Until its prerequisites are unlocked an achievement is not
evaluated at all, even if the achiever already qualifies for it.

### Secret achievements

Return `true` from `isSecret()` and, until it is unlocked,
`getDisplayName($user)` and `getDisplayDescription($user)` return placeholder
text instead of the real thing. Override `hiddenName()` and
`hiddenDescription()` to change the placeholders. The achievement itself is
still listed; only its text is hidden.

### Reading state

```php
FirstPostAchievement::getProgress($user);           // int
FirstPostAchievement::getProgressPercentage($user); // float
FirstPostAchievement::isUnlocked($user);
FirstPostAchievement::isVisibleTo($user);

AchievementTracker::getAchievementDisplay($user, 'first_post'); // everything above, cached
AchievementTracker::getAllAchievements();                       // definition classes, in dependency order
```

### Events

`AchievementStarted`, `AchievementProgressed` (carries the new total),
`AchievementCompleted` and `AchievementUnlocked`, all in
`Ram64\LaravelMerit\Events`, each with the stored record and the achiever.

## Experience and levels

```php
use Ram64\LaravelMerit\Concerns\CanExperience;

class User extends Authenticatable
{
    use CanExperience;
}
```

```php
$user->gainExperience(100, 'Completed a quest');
$user->loseExperience(25, 'Penalty');
$user->setExperience(500);
$user->setLevel(5);

$user->getExperience();
$user->getLevel();
$user->getExperienceProgress(); // ['current' => ..., 'required' => ..., 'percentage' => ...]
```

Levels come from `merit.experience.level_calculation`: `static` reads the
`level_thresholds` ladder, and `dynamic` evaluates `level_formula` for each
level up to `max_level`. Gaining experience can raise the level; losing it
never lowers the level. Only `setLevel()` can lower one, and only when
`allow_level_decrease` is true.

Each change is written to `experience_logs` unless `logging_enabled` is false,
and fires `ExperienceGained`, `ExperienceLost` or `LevelledUp`.

## Caching

Each stored record, and each achievement's name and description, is cached
per achiever for `cache_ttl` minutes and cleared when the tracker changes the
row it describes. The list of discovered definitions is cached for the same
time, so after a deploy that adds a definition, clear it (or the whole cache)
for the new achievement to appear straight away:

```php
AchievementTracker::clearDiscoveryCache();
```

To render a list, read the text of every achievement in one cache round trip
rather than two per achievement:

```php
$text = AchievementTracker::getDisplayTextMany($user, ['first_post', 'night_owl']);

// [
//     'first_post' => ['name' => 'First Post', 'description' => '...'],
//     'night_owl'  => ['name' => 'Hidden Achievement', 'description' => '...'],
// ]
```

The output matches `getDisplayName()` and `getDisplayDescription()` for every
key, secret placeholders included. Unknown keys are left out.

## Testing

```bash
composer test
composer analyse
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Security Vulnerabilities

Please report security issues privately by email to contact@ram64.com rather
than in a public issue.

## Credits

- [ram64](https://github.com/ram64)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
