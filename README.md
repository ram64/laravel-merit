# Laravel Merit

[![Latest Version on Packagist](https://img.shields.io/packagist/v/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/ram64/laravel-merit/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/ram64/laravel-merit/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/ram64/laravel-merit.svg?style=flat-square)](https://packagist.org/packages/ram64/laravel-merit)

Achievements, XP and levelling for Laravel. Each achievement is a PHP class in
your application; the database stores only each achiever's progress. Adding an
achievement needs no migration, and deleting one leaves its stored rows
orphaned rather than broken.

Requires PHP 8.3+ and Laravel 12.25+ or 13.

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
| `cleanup_on_delete` | `MERIT_CLEANUP_ON_DELETE` | `true` | Delete an achiever's rows when it is hard deleted |
| `images.path` | `MERIT_IMAGES_PATH` | `null` | Folder of achievement pictures; `null` means no lookups at all |
| `images.url` | `MERIT_IMAGES_URL` | `null` | Public URL of that folder |
| `images.extensions` | | `['webp', 'png']` | Tried in this order |
| `images.hidden` | `MERIT_IMAGES_HIDDEN` | `null` | Picture shown for a secret not yet unlocked |
| `icons.categories` | | `[]` | Default icon name per category |
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
result against the definition's current `goal()`. A progress row is created
only once an achievement has some progress, so an achievement nobody has
started has no row at all. Reaching the goal completes and unlocks the
achievement in the same pass, and an unlocked achievement stays unlocked
whatever its progress does afterwards; a check does not even recompute it.

A check reads all of the achiever's rows in one query. The unlock itself is
a conditional update, so two checks racing over the same achiever unlock
each achievement once and announce it once.

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
still listed; only its text is hidden. Its icon and picture are hidden the
same way: see [Icons and images](#icons-and-images).

### Reading state

```php
FirstPostAchievement::getProgress($user);           // int
FirstPostAchievement::getProgressPercentage($user); // float
FirstPostAchievement::isUnlocked($user);
FirstPostAchievement::isVisibleTo($user);

AchievementTracker::getAchievementDisplay($user, 'first_post');      // everything above, plus icon and image_url, cached
AchievementTracker::getAchievementDisplayMany($user, $keys);         // the same for a page of them
AchievementTracker::getAllAchievements();                            // definition classes, in dependency order
AchievementTracker::getVisibleAchievements($user);                   // without locked secrets
```

### Events

`AchievementStarted`, `AchievementProgressed` (carries the new total),
`AchievementCompleted` and `AchievementUnlocked`, all in
`Ram64\LaravelMerit\Events`, each with the stored record and the achiever.

They fire after the row is saved, so a listener reading the achiever's state
sees the change. That makes them at most once: a listener that throws leaves
the unlock stored, and it is not announced again. Listeners run
synchronously unless the listener implements `ShouldQueue`.

## Icons and images

An achievement can show an icon, a picture, or both. Both are optional and
null unless you set them, and the package never renders either: it hands you
an icon name and an image URL to use in your own views.

### Icons

Return an icon name from `icon()`. It is passed through untouched, so it can
come from any icon set your UI renders, such as a Blade icon:

```php
public static function icon(?Model $achiever = null): ?string
{
    return 'tabler-crown';
}
```

An achievement without one can take a default for its category:

```php
// config/merit.php
'icons' => [
    'categories' => ['fishing' => 'tabler-fish'],
],
```

### Images by naming convention

Point the package at a folder of pictures named by achievement key, and it
finds each one for you:

```php
// config/merit.php
'images' => [
    'path' => public_path('images/achievements'),
    'url' => '/images/achievements',
    'extensions' => ['webp', 'png'],
    'hidden' => null,
],
```

With that, `first_post` shows `public/images/achievements/first_post.webp`,
or `first_post.png` if there is no WebP; the extensions are tried in order.
With no `path`, nothing is looked up at all.

Recommended art: square, 512 × 512 pixels, transparent WebP, one file per
achievement, named by its key (`first_post.webp`).

To use something else for one achievement, return it from `image()`: a path
relative to the folder (`'badges/gold.webp'`), or a full URL
(`'https://cdn.example.com/first-post.png'`), which is used exactly as given.

A URL built from the folder carries the file's modified time
(`/images/achievements/first_post.webp?v=1761471234`), so replacing a picture
changes its URL and browsers and CDNs fetch the new one.

### Secrets

Until a secret is unlocked, it shows `hiddenIcon()` instead of its icon and
`images.hidden` instead of its picture, through the same check that hides its
name and description. Both are null by default, so nothing shows. Set
`images.hidden` to a mystery picture (relative to the folder, or a full URL),
and override `hiddenIcon()` on a definition to give it a placeholder icon.

### Reading them

```php
FirstPostAchievement::getDisplayIcon($user);   // 'tabler-crown', the category default, or null
FirstPostAchievement::getDisplayImage($user);  // '/images/achievements/first_post.webp?v=...', or null
```

`getAchievementDisplay()`, `getAchievementDisplayMany()` and
`getDisplayTextMany()` include both, as `icon` and `image_url`, and a page of
them is still one cache round trip. Keep `icon()` and `image()` cheap: unlike
the name and description, they are not cached per achiever.

### After adding or replacing pictures

Which files exist, and when each was last modified, is read once when the
definitions are discovered and cached with them for `cache_ttl` minutes, so
showing a page never touches the disk. A picture you add or replace is picked
up when that entry expires, or straight away once you clear it, for example
as a deploy step:

```bash
php artisan cache:clear
```

or `AchievementTracker::clearDiscoveryCache()`, which clears only Merit's
discovery entry. Long-running workers (queue workers, Octane) also hold it in
memory: restart them, as on any deploy.

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
level up to `max_level`. The formula is arithmetic on `$level` (numbers,
`+ - * / % **`, parentheses and common math functions, never `eval()`), or
the name of an invokable class.

Gaining experience can raise the level; losing it never lowers the level.
Only `setLevel()` can lower one, and only when `allow_level_decrease` is
true, in which case experience comes down to the new level's threshold too.
Each change locks the experiencer row, so concurrent awards do not overwrite
each other.

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
`getAchievementDisplayMany()` does the same for the full payload, building
anything not cached from one query.

## Deleting an achiever

Deleting a model also deletes its achievement progress, experience and
experience log, on a hard delete only: a soft-deleted model keeps them, so a
restore brings them back. Set `cleanup_on_delete` to false to keep them
always. A mass delete through the query builder fires no model events and is
not affected.

## Documentation

- [Prerequisites](https://github.com/ram64/laravel-merit/blob/main/docs/prerequisites.md)
- [Experience and levels](https://github.com/ram64/laravel-merit/blob/main/docs/experience.md)
- [Secret achievements](https://github.com/ram64/laravel-merit/blob/main/docs/SECRET_ACHIEVEMENTS.md)
- [Caching](https://github.com/ram64/laravel-merit/blob/main/docs/CACHING.md)
- [Debug mode](https://github.com/ram64/laravel-merit/blob/main/docs/DEBUG_MODE.md)
- [make:merit-achievement](https://github.com/ram64/laravel-merit/blob/main/docs/MAKE_ACHIEVEMENT_COMMAND.md)
- [Publishing assets](https://github.com/ram64/laravel-merit/blob/main/docs/PUBLISHING.md)

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
