# Publishing Laravel Merit Assets

The package registers two publish tags: `merit-migrations` and `merit-config`.

## Migrations

The migrations are not run from the package. Publish them into your
application, then migrate:

```bash
php artisan vendor:publish --tag="merit-migrations"
php artisan migrate
```

This creates three tables: `achiever_achievements`, `experiencers` and
`experience_logs`. Each migration reads its table name from the config
(`merit.achievements_table`, `merit.experience.table` and
`merit.experience.logs_table`), so set any custom names before migrating.

## Configuration

```bash
php artisan vendor:publish --tag="merit-config"
```

This copies `vendor/ram64/laravel-merit/config/merit.php` to
`config/merit.php`. Publishing is optional: without it the package's defaults
apply, and each option can still be set from the environment.

To publish both at once:

```bash
php artisan vendor:publish --provider="Ram64\LaravelMerit\LaravelMeritServiceProvider"
```

## Environment Variables

```env
MERIT_ACHIEVEMENTS_PATH="Definitions/Achievements"   # relative to app/
MERIT_ACHIEVEMENTS_TABLE="achiever_achievements"
MERIT_CACHE_TTL=10                                    # minutes
MERIT_QUEUE_EVENTS=true                               # run check() on a queued job
MERIT_QUEUE_CONNECTION=redis
MERIT_QUEUE_NAME=achievements
MERIT_DEBUG=false
```

If you move the definitions, set `achievements_namespace` to match in the
published config (or `MERIT_ACHIEVEMENTS_NAMESPACE`), ending in a backslash.

The experience options (`MERIT_EXPERIENCE_TABLE`, `MERIT_LEVEL_CALCULATION`,
`MERIT_MAX_LEVEL`, `MERIT_ALLOW_LEVEL_DECREASE` and the rest) are listed in
`config/merit.php`.

## Complete Setup Example

```bash
composer require ram64/laravel-merit

php artisan vendor:publish --tag="merit-migrations"
php artisan vendor:publish --tag="merit-config"
php artisan migrate

php artisan make:merit-achievement FirstPostAchievement
```

Then add the `CanAchieve` trait (and `CanExperience`, for XP and levels) to
your user model.
