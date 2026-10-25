# Achievement Prerequisites

An achievement can require others to be unlocked first. Until they are, a
check does not evaluate it at all, even if the achiever already qualifies.

## Declaring prerequisites

Override `prerequisite()`. It is declared on `BaseAchievementDefinition`, so
every definition has it, and returns `null` by default.

```php
public static function prerequisite(): string|array|null
{
    return null;                          // no requirement
    return 'first_post';                  // one achievement, by key
    return FirstPostAchievement::class;   // one achievement, by class
    return [                              // several: ALL of them
        'first_post',
        TenthPostAchievement::class,
    ];
}
```

A class name is turned into that definition's `key()`. Anything else is
treated as a key, so a typo in a key is never an error: it names an
achievement nobody can unlock, and the dependent achievement can never start.

## All, not any

An array means every prerequisite must be unlocked. There is no "any of".

`canStart()` is a hard gate, so think about reachability: an achievement
whose prerequisite an achiever can never unlock is one they can qualify for
and never be awarded. If an achievement can be reached by two different
routes, chaining it to either route gates it behind a step the other route
never takes. In that case leave `prerequisite()` as `null` and put the
condition in `qualifiesFor()` / `calculateCurrentProgress()` instead.

A prerequisite counts once it is **unlocked** (`unlocked_at` is set), not
merely completed.

## Order of evaluation

Achievements are checked in dependency order (a topological sort of the
prerequisite graph), so a prerequisite is always evaluated before the
achievements that depend on it. A whole chain can therefore complete in a
single check: unlocking the first rung makes the second startable within the
same pass.

A cycle (A requires B, B requires A) cannot be sorted. It is detected, and
rather than hang, the check falls back to discovery order for every
achievement, so chains elsewhere may then take more than one check to
complete. With debug mode on, a "Cycle detected" line is logged. A check can
never start either achievement in the cycle; only award() can unlock them.

## Checking by hand

```php
if (PostMasterAchievement::canStart($user)) {
    // every prerequisite is unlocked
}
```

During a check the answer comes from the achiever's rows, read once for the
whole check; outside one, `canStart()` makes one query.

## Example

```php
namespace App\Definitions\Achievements;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;

class PostMasterAchievement extends AchievementDefinition
{
    public static function key(): string
    {
        return 'post_master';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Post Master';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Write 100 posts';
    }

    public static function goal(): int
    {
        return 100;
    }

    public static function prerequisite(): string|array|null
    {
        return ['first_post', TenthPostAchievement::class];
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

## With make:merit-achievement

The command asks whether the achievement has prerequisites, then whether
there are several, then for the keys one at a time (an empty answer
finishes). It writes them into `prerequisite()` as given.
