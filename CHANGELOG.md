# Changelog

All notable changes to `laravel-merit` will be documented in this file.

## 0.2.0 - 2026-10-25

Almost everything since v0.1.6 is new: achievements as definition classes,
prerequisites, secret achievements, the experience and levelling system,
caching, and a test suite. The notes below cover what changes for code
already written against `main` before this release.

### Upgrading

- **PHP 8.3 and Laravel 12.25 or 13** are now the minimums, and the
  package requires `laravel/framework` rather than `illuminate/contracts`.
  Laravel 11 is dropped: it is out of security support, every 11.x release
  carries an open advisory, and current Composer refuses to install any of
  them.
- **Publish tags** are `merit-migrations` and `merit-config`. The tags the
  README used to give published nothing.
- **Morph maps.** If your application registers a morph map, rows written
  before this release by `AchievementTracker` (in `achiever_achievements`)
  and by the experience log (in `experience_logs`) hold the class name, and
  are now read by alias. Update them, for example
  `DB::table('achiever_achievements')->where('achiever_type', User::class)->update(['achiever_type' => 'user'])`,
  and the same for `experience_logs.experiencer_type`. Without a morph map
  nothing changes.
- **The `status` column** is now written by every path. Rows the tracker
  wrote before still say `started`; bring them in line with
  `DB::table('achiever_achievements')->whereNotNull('unlocked_at')->update(['status' => 'unlocked'])`
  and
  `DB::table('achiever_achievements')->whereNull('unlocked_at')->where('progress', '>', 0)->update(['status' => 'in_progress'])`.
- **Deleting an achiever** now deletes its achievement rows, experiencer row
  and experience log, on a hard delete. Set `merit.cleanup_on_delete` to
  false to keep the old behaviour.
- **Experience table names.** The experience migrations read
  `merit.experience.table` and `merit.experience.logs_table`, the keys the
  models always used, instead of `merit.experience_table` and
  `merit.experience_logs_table`. Only matters if you overrode the old keys.
- **Removed:** the `LaravelMerit` facade and its alias (it resolved an empty
  class), the unused `merit.achiever_model` key, and the empty views
  registration.

### Changed

- Achievement events fire after the row is saved and its caches cleared, so a
  listener sees the new state. They are at most once: a listener that throws
  leaves the unlock stored rather than rolled back for a retry.
- The events no longer implement `ShouldQueue`, which did nothing on an
  event. Queue a listener by having the listener implement it.
- A check stores progress against the definition's current `goal()`, not the
  goal the row was created with.
- `setLevel()` lowering a level (with `allow_level_decrease`) brings
  experience down to that level's threshold, so the next gain does not
  restore the old level.
- Under a dynamic formula, level 1 needs 0 experience, in
  `getExperienceForLevel()` and `precalculateLevelRequirements()`.
- At the top of the ladder (the last threshold, or `max_level`) there is no
  next level: `getExperienceForNextLevel()` is `PHP_INT_MAX` and progress
  reads `required` 0, 100%.
- `unlockOrProgress()` defaults the goal to the definition's `goal()` rather
  than 1. Pass a goal explicitly to keep the old value.
- `getVisibleAchievements()` returns a list.
- The migrations no longer add a second index on the morph columns. New
  installs only.

### Added

- `AchievementTracker::getAchievementDisplayMany()`: display payloads for a
  page of achievements in one cache round trip, misses built from one query.
- `AchievementTracker::getDisplayTextMany()`: names and descriptions for a
  page of achievements in one cache round trip.
- `AchievementTracker::clearAchievementCache()` for one achievement.
- `level_formula` may name an invokable class.
- `merit.cleanup_on_delete` and `merit.queue_name`.
- Definitions extending `BaseAchievementDefinition` directly now work:
  `prerequisite()`, `category()` and `canStart()` live on the base.
- Laravel 13 support.

### Fixed

- Two checks running at once could both unlock an achievement and fire its
  events twice. The unlock is now a conditional update; one wins.
- Concurrent experience changes could overwrite each other. Each change now
  re-reads the row under a lock.
- The level formula was run through `eval()`. It is now parsed as arithmetic.
- A check cost one query per achievement, and recomputed progress for
  achievements already unlocked. It is now one query for the achiever's
  rows, and unlocked achievements are skipped.
- Building a display payload read the same row five times; a page of them
  on the database cache driver ran hundreds of queries.
- `unlockOrProgress()` cleared every achievement's caches on each call.
- The tracker and the experience log store `getMorphClass()` in the
  `*_type` columns, as Eloquent's relations do.
- A cached discovery list naming a deleted definition class no longer fails
  every check until the cache expires.
- Caches are cleared on any stored change, including a fall in progress.
- `unlockOrProgress()` / `incrementProgress()`: no longer fails without an
  achiever, no longer re-stamps `unlocked_at` or re-fires the unlock events,
  writes `completed_at`, and reports the new total on
  `AchievementProgressed`.
- `getExperienceProgress()` never reports a negative percentage, and
  measures level 1 from zero.
- `make:merit-achievement` generates a class PHP can load, keeps
  `qualifiesFor()` intact for secret achievements, escapes quotes, returns
  real exit codes, and no longer recurses forever on a namespace outside the
  application.
- `ExperienceManager::getStatistics()` no longer depends on the global `DB`
  alias.
