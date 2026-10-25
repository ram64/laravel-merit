# Experience System

Any model can earn experience points (XP) and levels.

## Setup

### 1. Add the CanExperience trait to your model

```php
use Ram64\LaravelMerit\Concerns\CanExperience;

class User extends Authenticatable
{
    use CanExperience;
}
```

### 2. Publish and run the migrations

```bash
php artisan vendor:publish --tag="merit-migrations"
php artisan migrate
```

Two of the tables are for experience:

- `experiencers`: one row per model, holding its experience and level. It is
  created the first time the model's experience is read or changed.
- `experience_logs`: one row per change, unless logging is turned off.

## Basic Usage

### Award or remove experience

```php
$user->gainExperience(100, 'Completed tutorial');
$user->loseExperience(50, 'Penalty for rule violation');

// The same through the service
ExperienceManager::awardExperience($user, 100, 'Completed tutorial');
ExperienceManager::deductExperience($user, 50, 'Penalty for rule violation');
```

An amount of zero or less is refused (the method returns `false`).
Experience never goes below 0.

### Read experience

```php
$user->getExperience();          // current XP
$user->getLevel();               // current level
$user->getExperienceProgress();  // ['current' => ..., 'required' => ..., 'percentage' => ...]

// Everything at once, cached for experience.cache_ttl minutes
$user->getCachedExperienceData();
```

`getExperienceProgress()` measures from the start of the current level:
`current` is the XP earned into it, `required` the size of the step to the
next level, and `percentage` the bar to draw, between 0 and 100.
`required - current` is the XP still needed for the next level. At the top
of the ladder there is no next level: `required` is 0 and `percentage` is
100.

### Set experience or level directly

```php
$user->setExperience(500, 'Admin adjustment');   // a gain or a loss of the difference
$user->setLevel(5, 'Promoted by admin');
```

## How levels move

- **Gaining** experience can raise the level, by several levels at once,
  firing one `LevelledUp`.
- **Losing** experience never lowers the level. An achiever can end up below
  their own level's threshold; their progress then reads 0% until they climb
  back past it, and `current` goes negative by the shortfall.
- **`setLevel()` upwards** raises experience to the target level's threshold
  if it is below it.
- **`setLevel()` downwards** is refused (it returns `true` and changes
  nothing) unless `experience.allow_level_decrease` is true. When allowed, it
  also brings experience down to the target level's threshold, firing
  `ExperienceLost` for the difference, so the next gain does not recalculate
  the old level. There is no level-down event.
- **Changing the thresholds or the formula** leaves stored levels as they
  are. The next gain recalculates the level, and even then it never drops.

Each change re-reads the experiencer row under a row lock inside a
transaction, so two changes at once (two queued jobs, say) cannot overwrite
each other. Events fire after the change is committed.

## Level Calculation

Set `experience.level_calculation` to `static` or `dynamic`.

### Static thresholds (default)

```php
'experience' => [
    'level_calculation' => 'static',
    'level_thresholds' => [
        1 => 0,
        2 => 100,
        3 => 250,
        4 => 500,
        5 => 1000,
    ],
],
```

List the levels in ascending order. The top of the list is the highest level.

### Dynamic formula

```php
'experience' => [
    'level_calculation' => 'dynamic',
    'level_formula' => '50 * $level * $level',
    'max_level' => 100,
],
```

The formula gives the XP needed to reach a level. Level 1 always needs 0.
It is an arithmetic expression using `$level`, numbers, `+ - * / % **` and
parentheses, and the functions `abs`, `ceil`, `exp`, `floor`, `intdiv`,
`log`, `max`, `min`, `pow`, `round` and `sqrt`. Anything else is refused with
an `InvalidArgumentException`; the formula is never passed to `eval()`.

For a ladder arithmetic cannot express, give the name of an invokable class
instead. Unlike a closure, a class name survives `php artisan config:cache`.

```php
'level_formula' => App\Support\LevelLadder::class,

class LevelLadder
{
    public function __invoke(int $level): int
    {
        return /* XP needed for $level */;
    }
}
```

### Level requirements

```php
ExperienceManager::precalculateLevelRequirements();    // [1 => 0, 2 => 200, 3 => 450, ...] up to max_level
ExperienceManager::precalculateLevelRequirements(50);  // up to level 50
ExperienceManager::getCachedLevelRequirements();       // the same, cached
ExperienceManager::getExperienceForLevel(5);
ExperienceManager::calculateLevel(1200);
```

For a static ladder, `precalculateLevelRequirements()` returns the configured
thresholds.

## Events

All three carry the experiencer, the amount or levels, the reason, and the
log row (null when logging is off).

```php
use Ram64\LaravelMerit\Events\ExperienceGained;
use Ram64\LaravelMerit\Events\ExperienceLost;
use Ram64\LaravelMerit\Events\LevelledUp;

Event::listen(ExperienceGained::class, fn ($event) => /* $event->amount, $event->reason */);
Event::listen(ExperienceLost::class, fn ($event) => /* $event->amount, $event->reason */);
Event::listen(LevelledUp::class, fn ($event) => /* $event->oldLevel, $event->newLevel */);
```

Listeners run synchronously unless the listener class implements
`ShouldQueue`.

## Experience Logging

```php
'experience' => [
    'logging_enabled' => true,
],
```

Each row stores `experience_before`, `experience_after`, `level_before` and,
when the change raised the level, `level_after` (otherwise null). A level up
is recorded on the row of the change that caused it, not a row of its own.

```php
$logs = $user->experienceLogs()->limit(10)->get();
$logs = ExperienceManager::getExperienceLogs($user, 10);

ExperienceLog::gained()->get();     // experience_after > experience_before
ExperienceLog::lost()->get();       // experience_after < experience_before
ExperienceLog::levelUps()->get();   // level_after > level_before

$log->isGain();
$log->isLoss();
$log->isLevelUp();
$log->experience_change;            // after - before
```

## Configuration

```php
'experience' => [
    'table' => 'experiencers',
    'logs_table' => 'experience_logs',
    'logging_enabled' => true,
    'cache_ttl' => 10,                 // minutes, for getCachedExperienceData()
    'level_calculation' => 'static',   // or 'dynamic'
    'level_thresholds' => [/* ... */],
    'level_formula' => '50 * $level * $level',
    'max_level' => 100,                // dynamic only
    'allow_level_decrease' => false,   // lets setLevel() go down
],
```

The migrations read the two table names, so set them before migrating.

## Advanced Usage

### Bulk awards

```php
$results = ExperienceManager::bulkAwardExperience([
    ['experiencer' => $user1, 'amount' => 100, 'reason' => 'Daily login'],
    ['experiencer' => $user2, 'amount' => 150, 'reason' => 'Quest completion'],
]);
```

Each entry reports `success`; one failing does not stop the rest.

### Statistics

```php
ExperienceManager::getStatistics();
// total_experiencers, total_experience_awarded, total_experience_lost,
// total_level_ups, average_level, highest_level
```

## Deleting a model

Deleting a model with `CanExperience` deletes its experiencer row and its log
too, on a hard delete only: a soft-deleted model keeps them. Set
`merit.cleanup_on_delete` to false to keep them always.

## With achievements

```php
class ReachLevelFiveAchievement extends AchievementDefinition
{
    public static function qualifiesFor(Model $achiever): bool
    {
        return $achiever->getLevel() >= 5;
    }

    // ...
}
```

Award experience for an unlock from a listener on `AchievementUnlocked`.
