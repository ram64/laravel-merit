# Caching in Laravel Merit

Laravel Merit caches through your application's default cache store.
Everything it caches expires after `merit.cache_ttl` minutes (10 by default),
except the experience payload, which uses `merit.experience.cache_ttl`.

```env
MERIT_CACHE_TTL=15
MERIT_EXPERIENCE_CACHE_TTL=10
```

## What Gets Cached

The keys below are stable and can be read directly, for example to batch
your own lookups. `[class]` is the achiever's class name (not its morph
alias), `[id]` its key, `[key]` the achievement key.

| Key | Holds | TTL |
| --- | --- | --- |
| `merit:achievements:discovery:[hash]` | the discovered definition classes, and the image folder's files with their modified times | `cache_ttl` |
| `merit:record:[class]:[id]:[key]` | the stored progress row | `cache_ttl` |
| `merit:name:[class]:[id]:[key]` | the real name, from `name($achiever)` | `cache_ttl` |
| `merit:description:[class]:[id]:[key]` | the real description | `cache_ttl` |
| `merit:display:[class]:[id]:[key]` | the full `getAchievementDisplay()` payload | `cache_ttl`, half that until unlocked |
| `merit:experience:[id]:[class]` | `getCachedExperienceData()` | `experience.cache_ttl` |
| `merit:level_requirements:[max]` | `getCachedLevelRequirements()` | `experience.cache_ttl` |

Only the **real** name and description of a secret achievement are cached,
never the placeholder. Code reading `merit:name:` or `merit:description:`
directly must apply the secret gate itself (`isTextHiddenFrom()`), or use
`getDisplayTextMany()`, which does.

## Reading a page of achievements

The singular accessors cost a cache read or two each, which on the
`database` cache driver is a query each. For a list, use the batch forms:

```php
// Name and description: hits in one round trip
AchievementTracker::getDisplayTextMany($user, $keys);

// The full payload: hits in one round trip; misses built from one query
// for all of the achiever's rows, then stored together
AchievementTracker::getAchievementDisplayMany($user, $keys);
```

Both give exactly what the singular calls give for each key, omit unknown
keys, and keep the order asked for.

## Invalidation

The tracker clears an achievement's record, name, description and display
caches for an achiever whenever it changes that achiever's row: through a
check (`check()` / `processCheck()`), `increment()`, `award()` or
`unlockOrProgress()`. Any stored change counts, a fall in progress
included. Experience changes clear the experience payload.

By hand:

```php
AchievementTracker::clearAchievementCache($user, 'first_post'); // one achievement
AchievementTracker::clearUserCache($user);                      // every achievement
$user->clearAchievementCaches();                                // the same, from the model
$user->clearExperienceCaches();
AchievementTracker::clearDiscoveryCache();
```

`clearUserCache()` deletes four keys per defined achievement, so prefer the
single-achievement form where you know what changed.

## Discovery and deploys

The definition list is cached for `cache_ttl`, so a definition **added** in a
deploy is not seen until the entry expires. Clear it as part of the deploy:

```php
AchievementTracker::clearDiscoveryCache(); // or php artisan cache:clear
```

A definition **deleted** or renamed in a deploy is handled: a cached list
naming a class that no longer exists is thrown away and the directory is
scanned again.

The same entry holds the list of files in `merit.images.path` and their
modified times, read in the same pass, so achievement pictures are found and
versioned without touching the disk on each page. A picture **added or
replaced** is likewise not seen until the entry expires or is cleared the same
way. With no `merit.images.path`, the folder is never read.

A display payload cached by 0.2 lacks `icon` and `image_url`. It is treated
as a miss, built again and stored over the old one, so no reader sees it
without them.

Long-running workers (queue workers, Octane) also keep the list in memory for
the life of the process. Restart them on deploy, as you would for any code
change.

## Names and descriptions that change

`name($achiever)` and `description($achiever)` may depend on the achiever
(for example "3 of 10 caught"). The cached text is refreshed when the
achievement's row changes; if the text depends on anything else, it can be up
to `cache_ttl` minutes old.

## Troubleshooting

Turn on debug mode (`MERIT_DEBUG=true`) and look for lines such as:

```
[Merit] Loaded achievements from cache {"count":15}
[Merit] Retrieved achievement display from cache {"achievement":"first_post"}
[Merit] Batch display read {"requested":24,"resolved":24,"built":3}
```

For stale data, clear the caches above or run `php artisan cache:clear`.
