<?php

namespace Ram64\LaravelMerit\Definitions\Achievements;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Support\ImageUrl;

/**
 * Base class for all Achievements.
 * Implement the abstract methods to define specific achievements.
 */
abstract class BaseAchievementDefinition
{
    /**
     * Unlocked achievement keys per achiever, held only for the length of an
     * open window. Shared across every definition, because it is keyed by
     * achiever rather than by achievement.
     *
     * @var array<string, array<string, int>>
     */
    private static array $unlockedKeys = [];

    /**
     * Stored rows per achiever, keyed by achievement key, held for the length
     * of an open records window. A key held as null means "no row".
     *
     * @var array<string, array<string, AchieverAchievement|null>>
     */
    private static array $records = [];

    /**
     * Unique identifier for the achievement
     */
    abstract public static function key(): string;

    /**
     * Fetch the achiever's unlocked keys once and hold them until
     * flushUnlockedKeys().
     *
     * Both gates that ask "has this achiever unlocked X" — the prerequisite
     * check in canStart() and the secret check in isTextHiddenFrom() — answer
     * from the held set while it is open, so a pass over many achievements
     * costs one query rather than one per achievement.
     *
     * Pair with flushUnlockedKeys() in a finally. While a window is open, an
     * unlock made outside noteUnlocked() will not be seen.
     *
     * A caller that has already read the achiever's rows passes the unlocked
     * keys in, and no query is made.
     *
     * @param  array<int, string>|null  $unlockedKeys
     */
    public static function primeUnlockedKeys(Model $achiever, ?array $unlockedKeys = null): void
    {
        self::$unlockedKeys[self::achieverKey($achiever)] = $unlockedKeys === null
            ? static::fetchUnlockedKeys($achiever)
            : array_flip($unlockedKeys);
    }

    /**
     * Close the window opened by primeUnlockedKeys().
     */
    public static function flushUnlockedKeys(?Model $achiever = null): void
    {
        if ($achiever === null) {
            self::$unlockedKeys = [];

            return;
        }

        unset(self::$unlockedKeys[self::achieverKey($achiever)]);
    }

    /**
     * Whether a window is already open for this achiever.
     *
     * Callers use this to avoid closing a window somebody above them opened.
     */
    public static function hasUnlockedKeysWindow(Model $achiever): bool
    {
        return isset(self::$unlockedKeys[self::achieverKey($achiever)]);
    }

    /**
     * Record an unlock against an open window.
     *
     * This is what lets a chain complete in a single pass: a prerequisite
     * unlocked earlier in a run has to be visible to its dependents later in
     * the same run. A no-op when no window is open.
     */
    public static function noteUnlocked(Model $achiever, string $achievementKey): void
    {
        $achieverKey = self::achieverKey($achiever);

        if (isset(self::$unlockedKeys[$achieverKey])) {
            self::$unlockedKeys[$achieverKey][$achievementKey] = 1;
        }
    }

    /**
     * The held set for this achiever, or null when no window is open.
     *
     * @return array<string, int>|null
     */
    protected static function unlockedKeysWindow(Model $achiever): ?array
    {
        return self::$unlockedKeys[self::achieverKey($achiever)] ?? null;
    }

    /**
     * Every achievement key this achiever has unlocked.
     *
     * @return array<string, int>
     */
    protected static function fetchUnlockedKeys(Model $achiever): array
    {
        return array_flip(
            AchieverAchievement::where('achiever_id', $achiever->getKey())
                ->where('achiever_type', $achiever->getMorphClass())
                ->whereNotNull('unlocked_at')
                ->pluck('achievement_key')
                ->all()
        );
    }

    /**
     * Hold rows already read for an achiever, so getAchievementRecord()
     * answers from them rather than from the cache.
     *
     * Building one display payload asks for the same row five times over
     * (progress, percentage, completed, unlocked, started), and a page of
     * them asks for every row. With the rows held, each is read once, and
     * every accessor still goes through the definition, so an override still
     * applies. Keys not held fall through to the usual lookup.
     *
     * Pair with flushRecords() in a finally. While a window is open, a write
     * to a held row will not be seen.
     *
     * @param  array<string, AchieverAchievement|null>  $records
     */
    public static function primeRecords(Model $achiever, array $records): void
    {
        self::$records[self::achieverKey($achiever)] = $records;
    }

    /**
     * Close the window opened by primeRecords().
     */
    public static function flushRecords(Model $achiever): void
    {
        unset(self::$records[self::achieverKey($achiever)]);
    }

    /**
     * Whether a records window is already open for this achiever.
     */
    public static function hasRecordsWindow(Model $achiever): bool
    {
        return isset(self::$records[self::achieverKey($achiever)]);
    }

    /**
     * Identify one achiever within the held set.
     */
    private static function achieverKey(Model $achiever): string
    {
        return get_class($achiever).':'.$achiever->getKey();
    }

    /**
     * Display name for the achievement
     */
    abstract public static function name(?Model $achiever = null): string;

    /**
     * Get cached display name for the achievement
     */
    public static function getCachedName(?Model $achiever = null): string
    {
        if ($achiever === null) {
            return static::name(null);
        }

        $cacheKey = 'merit:name:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return static::name($achiever);
        });
    }

    /**
     * Description of what needs to be done
     */
    abstract public static function description(?Model $achiever = null): string;

    /**
     * Get cached display description for the achievement
     */
    public static function getCachedDescription(?Model $achiever = null): string
    {
        if ($achiever === null) {
            return static::description(null);
        }

        $cacheKey = 'merit:description:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return static::description($achiever);
        });
    }

    /**
     * An icon name for the achievement, such as a Blade icon ('tabler-crown'),
     * or null for none
     *
     * The package passes it through and never renders it, so it can name an
     * icon from any set. getDisplayIcon() adds the category default and the
     * secret gate.
     */
    public static function icon(?Model $achiever = null): ?string
    {
        return null;
    }

    /**
     * A picture for the achievement: a full URL, a path relative to
     * merit.images.path, or null to use the naming convention
     * ({merit.images.path}/{key}.webp, then .png)
     *
     * getDisplayImage() turns it into the URL to show, with the secret gate.
     */
    public static function image(?Model $achiever = null): ?string
    {
        return null;
    }

    /**
     * The target number to reach for completion
     */
    abstract public static function goal(): int;

    /**
     * Whether this achievement is secret (hidden until unlocked)
     */
    public static function isSecret(): bool
    {
        return false;
    }

    /**
     * Check if the achiever qualifies for this achievement
     */
    abstract public static function qualifiesFor(Model $achiever): bool;

    /**
     * Get the achievement(s) that must be completed before this one
     *
     * Declared here rather than on AchievementDefinition, along with
     * category() and canStart(), because the tracker calls all three on
     * every definition discovery accepts. A definition extending this class
     * directly used to be discovered and then fail on the first check.
     *
     * Can return:
     * - A single key (string): 'achievement_key'
     * - A single class (string): FirstPostAchievement::class
     * - Multiple mixed (array): ['achievement_key', SecondPostAchievement::class]
     */
    public static function prerequisite(): string|array|null
    {
        return null;
    }

    /**
     * Category for grouping achievements
     */
    public static function category(): string
    {
        return 'general';
    }

    /**
     * Check if this achievement can be started (prerequisites met)
     */
    public static function canStart(Model $achiever): bool
    {
        $prerequisites = static::prerequisite();

        if (! $prerequisites) {
            return true;
        }

        // Convert single prerequisite to array for uniform handling
        if (is_string($prerequisites)) {
            $prerequisites = [$prerequisites];
        }

        $unlocked = static::unlockedKeysWindow($achiever) ?? static::fetchUnlockedKeys($achiever);

        // All prerequisites must be unlocked, not any of them.
        foreach ($prerequisites as $prerequisite) {
            // Extract key from class name if it's a class
            if (! isset($unlocked[static::extractAchievementKey($prerequisite)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract achievement key from either a string key or AchievementDefinition class
     */
    protected static function extractAchievementKey(string $prerequisite): string
    {
        // If it's a class name (contains backslashes or ends with Achievement)
        if (str_contains($prerequisite, '\\') || class_exists($prerequisite)) {
            // Check if it's a valid AchievementDefinition class. key() is
            // abstract on the base, so a subclass always has it.
            if (
                class_exists($prerequisite) &&
                is_subclass_of($prerequisite, BaseAchievementDefinition::class)
            ) {
                return $prerequisite::key();
            }
        }

        // Otherwise, treat it as a direct key
        return $prerequisite;
    }

    /**
     * Calculate current progress based on achiever's actual data
     * This should return the current count/progress towards the goal
     */
    public static function calculateCurrentProgress(Model $achiever): int
    {
        // Default implementation: if qualifies, return goal, otherwise 0
        return static::qualifiesFor($achiever) ? static::goal() : 0;
    }

    /**
     * Get current progress for this achievement
     */
    public static function getProgress(Model $achiever): int
    {
        $record = static::getAchievementRecord($achiever);

        return $record ? $record->progress : 0;
    }

    /**
     * Check if achievement is completed (goal reached)
     */
    public static function isCompleted(Model $achiever): bool
    {
        return static::getProgress($achiever) >= static::goal();
    }

    /**
     * Check if achievement is unlocked (awarded to user)
     */
    public static function isUnlocked(Model $achiever): bool
    {
        $record = static::getAchievementRecord($achiever);

        return $record ? ($record->unlocked_at ? true : false) : false;
    }

    /**
     * Check if achievement has been started
     */
    public static function isStarted(Model $achiever): bool
    {
        return static::getAchievementRecord($achiever) !== null;
    }

    /**
     * Get the achievement record for this achiever
     */
    public static function getAchievementRecord(Model $achiever): ?AchieverAchievement
    {
        $held = self::$records[self::achieverKey($achiever)] ?? [];

        if (array_key_exists(static::key(), $held)) {
            return $held[static::key()];
        }

        $cacheKey = 'merit:record:'.get_class($achiever).':'.$achiever->getKey().':'.static::key();

        return Cache::remember($cacheKey, config('merit.cache_ttl', 10) * 60, function () use ($achiever) {
            return AchieverAchievement::where('achiever_id', $achiever->getKey())
                ->where('achiever_type', $achiever->getMorphClass())
                ->where('achievement_key', static::key())
                ->first();
        });
    }

    /**
     * Get achievement progress as a percentage
     */
    public static function getProgressPercentage(Model $achiever): float
    {
        $goal = static::goal();
        if ($goal === 0) {
            return 100.0;
        }

        return min(100.0, (static::getProgress($achiever) / $goal) * 100);
    }

    /**
     * Get remaining progress needed to complete
     */
    public static function getRemainingProgress(Model $achiever): int
    {
        return max(0, static::goal() - static::getProgress($achiever));
    }

    /**
     * Check if this achievement should be visible to the achiever
     * Secret achievements are only visible once unlocked
     */
    public static function isVisibleTo(Model $achiever): bool
    {
        if (! static::isSecret()) {
            return true;
        }

        return static::isUnlocked($achiever);
    }

    /**
     * The name shown in place of a secret achievement's own
     */
    public static function hiddenName(): string
    {
        return 'Hidden Achievement';
    }

    /**
     * The description shown in place of a secret achievement's own
     */
    public static function hiddenDescription(): string
    {
        return 'Complete certain actions to unlock this secret achievement.';
    }

    /**
     * The icon shown in place of a secret achievement's own, or null for none
     */
    public static function hiddenIcon(): ?string
    {
        return null;
    }

    /**
     * Whether the achiever should be shown the placeholder text rather than
     * the real thing.
     *
     * The gate sits in front of the cache, and only real text is ever cached,
     * so anything reading those keys directly has to re-apply this.
     *
     * isSecret() is checked first because it is a constant: a batch with no
     * secrets in it never asks about unlocks at all. When it does ask and a
     * window is open, it answers from there rather than spending a lookup per
     * secret achievement.
     */
    public static function isTextHiddenFrom(Model $achiever): bool
    {
        if (! static::isSecret()) {
            return false;
        }

        $unlocked = static::unlockedKeysWindow($achiever);

        if ($unlocked !== null) {
            return ! isset($unlocked[static::key()]);
        }

        return ! static::isUnlocked($achiever);
    }

    /**
     * Get the display name, hiding it if secret and not unlocked
     */
    public static function getDisplayName(Model $achiever): string
    {
        if (static::isTextHiddenFrom($achiever)) {
            return static::hiddenName();
        }

        return static::getCachedName($achiever);
    }

    /**
     * Get the display description, hiding it if secret and not unlocked
     */
    public static function getDisplayDescription(Model $achiever): string
    {
        if (static::isTextHiddenFrom($achiever)) {
            return static::hiddenDescription();
        }

        return static::getCachedDescription($achiever);
    }

    /**
     * The URL of the picture to show the achiever, or null for none
     *
     * A secret the achiever has not unlocked shows merit.images.hidden, behind
     * the same check as the name and description. Otherwise image() decides:
     * a full URL as it is, a path relative to the image folder as a versioned
     * URL. When image() is null, the folder is searched by key: {key}.webp,
     * then {key}.png (merit.images.extensions).
     */
    public static function getDisplayImage(?Model $achiever = null): ?string
    {
        if (static::isHiddenFrom($achiever)) {
            return ImageUrl::resolve(config('merit.images.hidden'));
        }

        return ImageUrl::resolve(static::image($achiever)) ?? ImageUrl::forKey(static::key());
    }

    /**
     * The icon to show the achiever, or null for none
     *
     * hiddenIcon() for a secret the achiever has not unlocked; otherwise
     * icon(), then the default for the category (merit.icons.categories).
     */
    public static function getDisplayIcon(?Model $achiever = null): ?string
    {
        if (static::isHiddenFrom($achiever)) {
            return static::hiddenIcon();
        }

        return static::icon($achiever) ?? static::categoryIcon();
    }

    /**
     * The configured default icon for this achievement's category, if any.
     */
    protected static function categoryIcon(): ?string
    {
        $icons = config('merit.icons.categories');
        $icon = is_array($icons) ? ($icons[static::category()] ?? null) : null;

        return is_string($icon) && $icon !== '' ? $icon : null;
    }

    /**
     * isTextHiddenFrom() for an achiever who may be absent. With nobody there
     * to have unlocked it, a secret stays hidden.
     */
    protected static function isHiddenFrom(?Model $achiever): bool
    {
        return $achiever === null ? static::isSecret() : static::isTextHiddenFrom($achiever);
    }
}
