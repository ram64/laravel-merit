# Changelog

All notable changes to `laravel-merit` will be documented in this file.

## Unreleased

Almost everything since v0.1.6 is new: achievements as definition classes,
prerequisites, secret achievements, the experience and levelling system,
caching, and a test suite. The notes below cover what changes behaviour for
code already written against the current `main`.

### Added

- `AchievementTracker::getDisplayTextMany()` reads the name and description of
  many achievements in one cache round trip.
- `merit.queue_name` (`MERIT_QUEUE_NAME`) sets the queue for the check job.

### Fixed

- The tracker and the experience log store `getMorphClass()` in the `*_type`
  columns, as Eloquent's relations do. With a morph map registered, rows
  written by one side were invisible to the other.
- A cached discovery list naming a deleted definition class no longer fails
  every check until the cache expires.
- Caches are cleared on any stored change to a progress row, including a fall
  in progress and a newly created row.
- `canStart()` and the secret gate answer from one lookup of the achiever's
  unlocked keys per check rather than one query per prerequisite.
- `unlockOrProgress()` / `incrementProgress()`: no longer fails without an
  achiever, no longer re-stamps `unlocked_at` or re-fires the unlock events on
  an unlocked row, writes `completed_at`, and reports the new total on
  `AchievementProgressed`.
- `getExperienceProgress()` measures level 1 from zero experience and never
  reports a negative percentage.
- `make:merit-achievement` generates a class PHP can load, keeps
  `qualifiesFor()` intact for secret achievements, escapes quotes in the
  description, returns real exit codes, and no longer recurses forever on a
  namespace outside the application.

### Upgrading

- **Morph maps.** If your application registers a morph map, rows written
  before this release by `AchievementTracker` (in `achiever_achievements`) and
  by the experience log (in `experience_logs`) hold the class name, and are
  now read by alias. Update them, for example
  `DB::table('achiever_achievements')->where('achiever_type', User::class)->update(['achiever_type' => 'user'])`,
  and the same for `experience_logs.experiencer_type`. Without a morph map
  nothing changes.
- **Experience table names.** The experience migrations read
  `merit.experience.table` and `merit.experience.logs_table`, the keys the
  models always used, instead of `merit.experience_table` and
  `merit.experience_logs_table`. Only matters if you overrode the old keys.
- **`unlockOrProgress()`** defaults the goal to the definition's `goal()`
  rather than 1. Pass a goal explicitly to keep the old value.
- **Publish tags** are `merit-migrations` and `merit-config`. The tags the
  README used to give published nothing.
