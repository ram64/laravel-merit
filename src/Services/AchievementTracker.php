<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Ram64\LaravelMerit\Definitions\Achievements\AchievementDefinition;
use Ram64\LaravelMerit\Definitions\Achievements\BaseAchievementDefinition;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementProgressed;
use Ram64\LaravelMerit\Events\AchievementStarted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Jobs\ProcessAchievementCheck;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Support\ImageIndex;

class AchievementTracker
{
    protected static $achievements = [];

    protected static $discovered = false;

    /**
     * The image folder's files and their modified times, read with discovery
     *
     * @var array<string, int>
     */
    protected static array $images = [];

    /**
     * Check all achievements for a given achiever
     */
    public static function check($achiever)
    {
        static::debug('Starting achievement check', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
            'queue_enabled' => config('merit.queue_achievements', true),
        ]);

        if (config('merit.queue_achievements', true)) {
            ProcessAchievementCheck::dispatch($achiever);
            static::debug('Achievement check dispatched to queue');
        } else {
            static::processCheck($achiever);
        }
    }

    /**
     * Process achievement check (called directly or via queue)
     */
    public static function processCheck($achiever)
    {
        $achievements = static::getAllAchievements();

        static::debug('Processing achievement check', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
            'total_achievements' => count($achievements),
        ]);

        // Every stored row for the achiever, in one query. It answers both
        // "is this already unlocked" and "is there a row for this yet", which
        // used to cost a query per achievement on every check.
        $records = static::fetchRecords($achiever);

        // The unlocked keys come from the same rows, so the prerequisite
        // checks below cost nothing further. Unlocks made below are fed back
        // in so a chain still completes in this single pass.
        $opened = ! AchievementDefinition::hasUnlockedKeysWindow($achiever);

        if ($opened) {
            AchievementDefinition::primeUnlockedKeys($achiever, array_keys(array_filter(
                $records,
                fn (AchieverAchievement $record) => $record->unlocked_at !== null
            )));
        }

        try {
            static::processAchievements($achiever, $achievements, $records);
        } finally {
            // Only close a window this call opened; a caller above may own one.
            if ($opened) {
                AchievementDefinition::flushUnlockedKeys($achiever);
            }
        }

        static::debug('Achievement check processing completed');
    }

    /**
     * The body of processCheck(), inside the prerequisite batch window.
     *
     * @param  array<int, class-string<BaseAchievementDefinition>>  $achievements
     * @param  array<string, AchieverAchievement>  $records
     */
    protected static function processAchievements($achiever, array $achievements, array $records): void
    {
        foreach ($achievements as $achievementClass) {
            $achievementKey = $achievementClass::key();
            $record = $records[$achievementKey] ?? null;

            static::debug("Checking achievement: {$achievementKey}", [
                'achievement_class' => $achievementClass,
                'achiever_id' => $achiever->getKey(),
            ]);

            // An unlocked achievement stays unlocked whatever its progress
            // does, so there is nothing to work out. Skipping it before
            // calculateCurrentProgress() also saves the definition's own query.
            if ($record !== null && $record->unlocked_at !== null) {
                static::debug("Achievement already unlocked: {$achievementKey}");

                continue;
            }

            // Skip if prerequisites not met
            if (! $achievementClass::canStart($achiever)) {
                static::debug("Prerequisites not met for achievement: {$achievementKey}");

                continue;
            }

            // Get current progress from achievement logic
            $currentProgress = $achievementClass::calculateCurrentProgress($achiever);

            static::debug('Current progress calculated', [
                'achievement' => $achievementKey,
                'progress' => $currentProgress,
                'goal' => $achievementClass::goal(),
            ]);

            // Skip if no progress at all - don't create records for 0 progress
            if ($currentProgress <= 0) {
                static::debug("No progress for achievement: {$achievementKey}");

                continue;
            }

            if ($record === null) {
                $record = AchieverAchievement::firstOrCreate(
                    [
                        'achiever_id' => $achiever->getKey(),
                        'achiever_type' => $achiever->getMorphClass(),
                        'achievement_key' => $achievementKey,
                    ],
                    [
                        'progress' => $currentProgress,
                        'goal' => $achievementClass::goal(),
                        'status' => 'in_progress',
                        'started_at' => now(),
                    ]
                );

                // Fire started event if this is the first time
                if ($record->wasRecentlyCreated) {
                    static::debug("Achievement started: {$achievementKey}");
                    event(new AchievementStarted($record, $achiever));
                }

                // Another check may have created the row, and even unlocked
                // it, since this one read the achiever's rows.
                if ($record->unlocked_at !== null) {
                    static::debug("Achievement already unlocked: {$achievementKey}");
                    AchievementDefinition::noteUnlocked($achiever, $achievementKey);

                    continue;
                }
            }

            static::advance($achiever, $achievementClass, $record, $currentProgress);
        }
    }

    /**
     * Move a locked row to new progress, persist it, and fire the events for
     * what changed.
     *
     * Every write path ends here, so the row comes out the same shape
     * whichever one touched it: the definition's current goal, a status that
     * matches the progress, and completed_at and unlocked_at set together.
     *
     * Events go out after the row is saved and its caches cleared, so a
     * listener that reads the achiever's state sees the new value. That also
     * makes them at most once: a listener that throws leaves the unlock
     * stored, and a later check will not announce it again.
     *
     * Reaching the goal completes and unlocks the row in one step. A row
     * already completed but left locked stays locked unless $award is set,
     * as it always has; award() is what unlocks those.
     *
     * @param  class-string<BaseAchievementDefinition>  $achievementClass
     */
    protected static function advance(Model $achiever, string $achievementClass, AchieverAchievement $record, int $progress, bool $award = false): void
    {
        $achievementKey = $achievementClass::key();

        // The goal is the definition's as it stands now. The row only
        // remembers the goal it was created with, which went stale whenever
        // a definition's goal changed, leaving rows stuck short of it.
        $record->goal = $achievementClass::goal();

        $oldProgress = $record->progress;
        $wasCompleted = $record->completed_at !== null;
        $record->progress = min($progress, $record->goal);
        $progressed = $record->progress > $oldProgress;
        $unlocking = $record->progress >= $record->goal && (! $wasCompleted || $award);

        if ($unlocking) {
            $claimed = static::claimUnlock($record);
        } else {
            $claimed = false;
            $record->status = $record->progress > 0 ? 'in_progress' : 'started';
            $record->save();
        }

        // Clear the caches once the new state is actually persisted. Any
        // stored change counts: a new row and a fall in progress leave a
        // cached read just as stale as a rise.
        if ($claimed || $record->wasRecentlyCreated || $record->wasChanged()) {
            static::forgetAchievementCaches($achiever, $achievementKey);
        }

        if ($unlocking && ! $claimed) {
            // Another check unlocked the row between this one reading it and
            // writing it. That check fired the events; firing them again
            // would announce the same unlock twice.
            static::debug("Achievement unlocked elsewhere: {$achievementKey}");
            AchievementDefinition::noteUnlocked($achiever, $achievementKey);

            return;
        }

        if ($progressed) {
            static::debug('Achievement progress updated', [
                'achievement' => $achievementKey,
                'old_progress' => $oldProgress,
                'new_progress' => $record->progress,
                'goal' => $record->goal,
            ]);
            event(new AchievementProgressed($record, $achiever, $record->progress));
        }

        if ($claimed) {
            static::debug("Achievement completed and unlocked: {$achievementKey}");

            if (! $wasCompleted) {
                event(new AchievementCompleted($record, $achiever));
            }

            AchievementDefinition::noteUnlocked($achiever, $achievementKey);
            event(new AchievementUnlocked($record, $achiever));
        }
    }

    /**
     * Write the unlock, unless another process unlocked the row first.
     *
     * The update only matches while unlocked_at is still null, so of two
     * checks racing to unlock the same row exactly one writes it, and only
     * that one goes on to fire the unlock events. The loser's copy of the row
     * is refreshed to what the winner stored.
     */
    protected static function claimUnlock(AchieverAchievement $record): bool
    {
        $now = now();

        $attributes = [
            'progress' => $record->progress,
            'goal' => $record->goal,
            'status' => 'unlocked',
            'completed_at' => $record->completed_at ?? $now,
            'unlocked_at' => $now,
        ];

        $claimed = AchieverAchievement::query()
            ->whereKey($record->getKey())
            ->whereNull('unlocked_at')
            ->update($attributes) === 1;

        if ($claimed) {
            $record->forceFill($attributes)->syncOriginal();
        } else {
            $record->refresh();
        }

        return $claimed;
    }

    /**
     * Every stored row for an achiever, keyed by achievement key.
     *
     * @return array<string, AchieverAchievement>
     */
    protected static function fetchRecords(Model $achiever): array
    {
        return AchieverAchievement::query()
            ->where('achiever_id', $achiever->getKey())
            ->where('achiever_type', $achiever->getMorphClass())
            ->get()
            ->keyBy('achievement_key')
            ->all();
    }

    /**
     * Manually award an achievement to an achiever
     */
    public static function award($achiever, string $achievementKey)
    {
        static::debug('Manually awarding achievement', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        $record = static::openRecord($achiever, $achievementClass);

        // Skip if already unlocked
        if ($record->unlocked_at !== null) {
            return $record;
        }

        // Straight to the goal, which unlocks it
        static::advance($achiever, $achievementClass, $record, $achievementClass::goal(), award: true);

        return $record;
    }

    /**
     * Increment progress towards an achievement
     */
    public static function increment($achiever, string $achievementKey, int $amount = 1)
    {
        static::debug('Incrementing achievement progress', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'amount' => $amount,
        ]);

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            static::debug("Achievement not found: {$achievementKey}");
            throw new \InvalidArgumentException("Achievement '{$achievementKey}' not found");
        }

        // Check if prerequisites are met
        if (! $achievementClass::canStart($achiever)) {
            static::debug("Prerequisites not met for increment: {$achievementKey}");

            return;
        }

        $record = static::openRecord($achiever, $achievementClass);

        // Skip if already unlocked
        if ($record->unlocked_at !== null) {
            return $record;
        }

        static::advance($achiever, $achievementClass, $record, $record->progress + $amount);

        return $record;
    }

    /**
     * Find or create the achiever's row for an achievement, at no progress,
     * firing the started event if this created it.
     *
     * @param  class-string<BaseAchievementDefinition>  $achievementClass
     */
    protected static function openRecord(Model $achiever, string $achievementClass): AchieverAchievement
    {
        $record = AchieverAchievement::firstOrCreate(
            [
                'achiever_id' => $achiever->getKey(),
                'achiever_type' => $achiever->getMorphClass(),
                'achievement_key' => $achievementClass::key(),
            ],
            [
                'progress' => 0,
                'goal' => $achievementClass::goal(),
                'status' => 'started',
                'started_at' => now(),
            ]
        );

        // Fire started event if this is the first time
        if ($record->wasRecentlyCreated) {
            event(new AchievementStarted($record, $achiever));
        }

        return $record;
    }

    /**
     * Register an achievement class
     */
    public static function register(string $achievementClass)
    {
        static::$achievements[$achievementClass::key()] = $achievementClass;
    }

    /**
     * Get a specific achievement class by key
     */
    public static function getAchievement(string $key): ?string
    {
        static::discoverAchievements();

        return static::$achievements[$key] ?? null;
    }

    /**
     * Get a specific achievement definition instance by key
     */
    public static function getDefinition(string $key): ?object
    {
        $className = static::getAchievement($key);

        if (! $className) {
            return null;
        }

        return new $className;
    }

    /**
     * Get the achievement definition from an AchieverAchievement model
     */
    public static function getDefinitionFromAchievement(AchieverAchievement $achievement): ?object
    {
        return static::getDefinition($achievement->achievement_key);
    }

    /**
     * Get all registered achievement classes
     */
    public static function getAllAchievements(): array
    {
        static::discoverAchievements();

        return static::sortAchievementsTopologically(array_values(static::$achievements));
    }

    /**
     * Sort achievements in topological order based on prerequisites
     */
    protected static function sortAchievementsTopologically(array $achievements): array
    {
        if (empty($achievements)) {
            return $achievements;
        }

        // Build dependency graph: achievement => [prerequisites]
        $graph = [];
        $inDegree = [];
        $keyToClass = [];

        foreach ($achievements as $achievementClass) {
            $key = $achievementClass::key();
            $keyToClass[$key] = $achievementClass;
            $graph[$key] = [];
            $inDegree[$key] = 0;
        }

        // Build the graph and in-degrees
        foreach ($achievements as $achievementClass) {
            $key = $achievementClass::key();
            $prerequisites = $achievementClass::prerequisite();

            if (! $prerequisites) {
                continue;
            }

            if (is_string($prerequisites)) {
                $prerequisites = [$prerequisites];
            }

            foreach ($prerequisites as $prerequisite) {
                // Extract prerequisite key
                $prereqKey = static::extractAchievementKey($prerequisite);

                // Only add edge if prerequisite exists in our achievements
                if (isset($graph[$prereqKey])) {
                    $graph[$prereqKey][] = $key; // prereq points to dependent
                    $inDegree[$key]++; // dependent has one more prerequisite
                }
            }
        }

        // Kahn's algorithm for topological sort
        $queue = [];
        $result = [];

        // Start with achievements that have no prerequisites (in-degree 0)
        foreach ($inDegree as $key => $degree) {
            if ($degree === 0) {
                $queue[] = $key;
            }
        }

        while (! empty($queue)) {
            $current = array_shift($queue);
            $result[] = $keyToClass[$current];

            // For each achievement that depends on current
            foreach ($graph[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Check for cycles (shouldn't happen with proper prerequisites)
        if (count($result) !== count($achievements)) {
            static::debug('Cycle detected in achievement prerequisites, falling back to unsorted order', [
                'total_achievements' => count($achievements),
                'sorted_count' => count($result),
            ]);

            return $achievements; // Fallback to original order
        }

        static::debug('Achievements sorted topologically', [
            'total' => count($result),
            'order' => array_map(fn ($class) => $class::key(), $result),
        ]);

        return $result;
    }

    /**
     * Extract achievement key from prerequisite (string or class)
     */
    protected static function extractAchievementKey(string $prerequisite): string
    {
        // If it's a class name (contains backslashes or is a valid class)
        if (str_contains($prerequisite, '\\') || class_exists($prerequisite)) {
            // Check if it's a valid AchievementDefinition class
            if (
                class_exists($prerequisite) &&
                method_exists($prerequisite, 'key')
            ) {
                return $prerequisite::key();
            }
        }

        // Otherwise, treat it as a direct key
        return $prerequisite;
    }

    /**
     * Get all achievement keys
     */
    public static function getAchievementKeys(): array
    {
        static::discoverAchievements();

        return array_keys(static::$achievements);
    }

    /**
     * Discover achievements from the configured directory
     */
    protected static function discoverAchievements()
    {
        if (static::$discovered) {
            return;
        }

        $cacheKey = static::getDiscoveryCacheKey();
        $cached = Cache::get($cacheKey);

        // A definition deleted or renamed since the list was cached would be a
        // fatal "class not found" on first use, for every check, until the
        // entry expired. A list naming a missing class is rediscovered instead,
        // as is an entry not in this shape.
        if (
            is_array($cached)
            && is_array($cached['achievements'] ?? null)
            && is_array($cached['images'] ?? null)
            && static::allClassesExist($cached['achievements'])
        ) {
            static::$achievements = $cached['achievements'];
            static::$images = $cached['images'];
            static::$discovered = true;
            static::debug('Loaded achievements from cache', ['count' => count($cached['achievements'])]);

            return;
        }

        // The image folder is read here, once, rather than a file at a time
        // as images are shown. See ImageIndex.
        static::$images = ImageIndex::scan(config('merit.images.path'));

        $achievementPath = config('merit.achievements_path', app_path('Achievements'));
        $achievementNamespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        static::debug('Starting achievement discovery', [
            'path' => $achievementPath,
            'namespace' => $achievementNamespace,
        ]);

        if (! File::exists($achievementPath)) {
            static::debug('Achievement path does not exist', ['path' => $achievementPath]);
            static::$discovered = true;

            return;
        }

        $files = File::allFiles($achievementPath);
        static::debug('Found files in achievement directory', ['count' => count($files)]);

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $className = $achievementNamespace.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($className)) {
                $reflection = new \ReflectionClass($className);

                // Skip abstract classes and classes that don't extend our base
                if (
                    $reflection->isAbstract() ||
                    ! $reflection->isSubclassOf(BaseAchievementDefinition::class)
                ) {
                    static::debug('Skipping class (abstract or invalid base)', ['class' => $className]);

                    continue;
                }

                static::debug('Registering achievement class', ['class' => $className]);
                static::register($className);
            } else {
                static::debug('Class does not exist', ['class' => $className]);
            }
        }

        // Cache the discovered achievements, and the image folder with them
        Cache::put($cacheKey, [
            'achievements' => static::$achievements,
            'images' => static::$images,
        ], static::getCacheTtl());

        static::debug('Achievement discovery completed and cached', [
            'registered' => count(static::$achievements),
            'cache_ttl_seconds' => static::getCacheTtl(),
        ]);
        static::$discovered = true;
    }

    /**
     * Whether every class in a cached discovery list can still be loaded
     *
     * @param  array<string, string>  $achievementClasses
     */
    protected static function allClassesExist(array $achievementClasses): bool
    {
        foreach ($achievementClasses as $achievementClass) {
            if (! class_exists($achievementClass)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all achievement classes visible to the achiever
     * This excludes secret achievements that haven't been unlocked
     */
    public static function getVisibleAchievements($achiever): array
    {
        $allAchievements = static::getAllAchievements();

        // A list, in dependency order. array_filter() kept the original keys,
        // so the result had gaps and JSON-encoded as an object.
        return array_values(array_filter($allAchievements, function ($achievementClass) use ($achiever) {
            return $achievementClass::isVisibleTo($achiever);
        }));
    }

    /**
     * Get the display name and description for many achievements at once.
     *
     * Rendering a list is the commonest thing an achievements package is asked
     * to do, and doing it through getDisplayName()/getDisplayDescription() is
     * two cache round trips per achievement — on the database cache driver,
     * two SELECTs each. This reads every name and description in one.
     *
     * Unknown keys are omitted rather than returned as nulls.
     *
     * Each entry also carries the icon and image URL, worked out from the
     * definition and the cached image index, so they add no round trip.
     *
     * @param  array<int, string>  $achievementKeys
     * @return array<string, array{name: string, description: string, icon: string|null, image_url: string|null}>
     */
    public static function getDisplayTextMany(Model $achiever, array $achievementKeys): array
    {
        $definitions = [];

        foreach (array_unique($achievementKeys) as $achievementKey) {
            $achievementClass = static::getAchievement($achievementKey);

            if ($achievementClass) {
                $definitions[$achievementKey] = $achievementClass;
            }
        }

        if ($definitions === []) {
            return [];
        }

        // Every key up front, so the hits cost one round trip between them.
        $cacheKeys = [];

        foreach ($definitions as $achievementKey => $achievementClass) {
            $cacheKeys[] = static::getNameCacheKey($achiever, $achievementKey);
            $cacheKeys[] = static::getDescriptionCacheKey($achiever, $achievementKey);
        }

        $cached = Cache::many($cacheKeys);

        // The secret gate asks whether the achiever has unlocked each secret
        // achievement, which is a lookup apiece and would undo the batching on
        // any page carrying a few of them. One window covers the lot. Pages
        // with no secrets on them — the common case — skip it entirely.
        $needsGate = false;

        foreach ($definitions as $achievementClass) {
            if ($achievementClass::isSecret()) {
                $needsGate = true;

                break;
            }
        }

        $opened = $needsGate && ! AchievementDefinition::hasUnlockedKeysWindow($achiever);

        if ($opened) {
            AchievementDefinition::primeUnlockedKeys($achiever);
        }

        try {
            return static::resolveDisplayText($achiever, $definitions, $cached, count($achievementKeys));
        } finally {
            // Only close a window this call opened; a caller above may own one.
            if ($opened) {
                AchievementDefinition::flushUnlockedKeys($achiever);
            }
        }
    }

    /**
     * Pair the cached text with the definitions, filling in whatever missed.
     *
     * @param  array<string, class-string<BaseAchievementDefinition>>  $definitions
     * @param  array<string, mixed>  $cached
     * @return array<string, array{name: string, description: string, icon: string|null, image_url: string|null}>
     */
    protected static function resolveDisplayText(Model $achiever, array $definitions, array $cached, int $requested): array
    {
        $display = [];
        $missed = 0;

        foreach ($definitions as $achievementKey => $achievementClass) {
            $name = $cached[static::getNameCacheKey($achiever, $achievementKey)] ?? null;
            $description = $cached[static::getDescriptionCacheKey($achiever, $achievementKey)] ?? null;

            if ($name === null || $description === null) {
                // Cold path. Hand the whole job back to the definition rather
                // than reproducing it, so an overridden accessor still wins and
                // the two paths cannot drift apart.
                $display[$achievementKey] = [
                    'name' => $achievementClass::getDisplayName($achiever),
                    'description' => $achievementClass::getDisplayDescription($achiever),
                    'icon' => $achievementClass::getDisplayIcon($achiever),
                    'image_url' => $achievementClass::getDisplayImage($achiever),
                ];
                $missed++;

                continue;
            }

            // Only real text is ever cached, so the secret gate has to be
            // re-applied here. Reading these keys and skipping it is how a
            // secret achievement's name leaks.
            if ($achievementClass::isTextHiddenFrom($achiever)) {
                $name = $achievementClass::hiddenName();
                $description = $achievementClass::hiddenDescription();
            }

            $display[$achievementKey] = [
                'name' => $name,
                'description' => $description,
                'icon' => $achievementClass::getDisplayIcon($achiever),
                'image_url' => $achievementClass::getDisplayImage($achiever),
            ];
        }

        static::debug('Batch display text read', [
            'achiever_id' => $achiever->getKey(),
            'requested' => $requested,
            'resolved' => count($display),
            'cold' => $missed,
        ]);

        return $display;
    }

    /**
     * Get achievement data with appropriate display names/descriptions
     */
    public static function getAchievementDisplay($achiever, string $achievementKey): array
    {
        $cacheKey = static::getDisplayCacheKey($achiever, $achievementKey);
        $cached = Cache::get($cacheKey);

        if (static::isCurrentDisplay($cached)) {
            static::debug('Retrieved achievement display from cache', [
                'achievement' => $achievementKey,
                'achiever_id' => $achiever->getKey(),
            ]);

            return $cached;
        }

        $achievementClass = static::getAchievement($achievementKey);

        if (! $achievementClass) {
            return [];
        }

        // One read of the row, held while the payload is built, rather than
        // one per accessor that asks for it.
        $displayData = static::withRecords(
            $achiever,
            [$achievementKey => $achievementClass::getAchievementRecord($achiever)],
            fn () => static::displayPayload(
                $achiever,
                $achievementClass,
                $achievementClass::getDisplayName($achiever),
                $achievementClass::getDisplayDescription($achiever)
            )
        );

        // Cache for shorter duration if not unlocked (progress may change)
        $cacheDuration = static::getDisplayCacheTtl($displayData);
        Cache::put($cacheKey, $displayData, $cacheDuration);

        static::debug('Cached achievement display data', [
            'achievement' => $achievementKey,
            'achiever_id' => $achiever->getKey(),
            'cache_duration' => $cacheDuration,
        ]);

        return $displayData;
    }

    /**
     * Get the display payload for many achievements at once.
     *
     * The batch form of getAchievementDisplay(), with the same output for
     * every key. Cached payloads come back in one cache round trip; the rest
     * are built from one query for all of the achiever's rows and one batch
     * read of their text, then cached together.
     *
     * Unknown keys are omitted rather than returned as empty arrays.
     *
     * @param  array<int, string>  $achievementKeys
     * @return array<string, array<string, mixed>>
     */
    public static function getAchievementDisplayMany(Model $achiever, array $achievementKeys): array
    {
        $definitions = [];

        foreach (array_unique($achievementKeys) as $achievementKey) {
            $achievementClass = static::getAchievement($achievementKey);

            if ($achievementClass) {
                $definitions[$achievementKey] = $achievementClass;
            }
        }

        if ($definitions === []) {
            return [];
        }

        $cacheKeys = [];

        foreach ($definitions as $achievementKey => $achievementClass) {
            $cacheKeys[$achievementKey] = static::getDisplayCacheKey($achiever, $achievementKey);
        }

        $cached = Cache::many(array_values($cacheKeys));
        $display = [];
        $missing = [];

        foreach ($cacheKeys as $achievementKey => $cacheKey) {
            if (static::isCurrentDisplay($cached[$cacheKey] ?? null)) {
                $display[$achievementKey] = $cached[$cacheKey];
            } else {
                $missing[$achievementKey] = $definitions[$achievementKey];
            }
        }

        if ($missing !== []) {
            $rows = static::fetchRecords($achiever);

            $held = [];

            foreach (array_keys($missing) as $achievementKey) {
                $held[$achievementKey] = $rows[$achievementKey] ?? null;
            }

            // The secret gate in the text read asks about unlocks; answer it
            // from the same rows rather than another query.
            $openedKeys = ! AchievementDefinition::hasUnlockedKeysWindow($achiever);

            if ($openedKeys) {
                AchievementDefinition::primeUnlockedKeys($achiever, array_keys(array_filter(
                    $rows,
                    fn (AchieverAchievement $record) => $record->unlocked_at !== null
                )));
            }

            try {
                $built = static::withRecords($achiever, $held, function () use ($achiever, $missing) {
                    $text = static::getDisplayTextMany($achiever, array_keys($missing));
                    $built = [];

                    foreach ($missing as $achievementKey => $achievementClass) {
                        $built[$achievementKey] = static::displayPayload(
                            $achiever,
                            $achievementClass,
                            $text[$achievementKey]['name'],
                            $text[$achievementKey]['description']
                        );
                    }

                    return $built;
                });
            } finally {
                if ($openedKeys) {
                    AchievementDefinition::flushUnlockedKeys($achiever);
                }
            }

            $byTtl = [];

            foreach ($built as $achievementKey => $payload) {
                $display[$achievementKey] = $payload;
                $byTtl[static::getDisplayCacheTtl($payload)][$cacheKeys[$achievementKey]] = $payload;
            }

            foreach ($byTtl as $ttl => $payloads) {
                Cache::putMany($payloads, $ttl);
            }
        }

        static::debug('Batch display read', [
            'achiever_id' => $achiever->getKey(),
            'requested' => count($achievementKeys),
            'resolved' => count($display),
            'built' => count($missing),
        ]);

        // In the order asked for.
        return array_replace(array_intersect_key($cacheKeys, $display), $display);
    }

    /**
     * The display payload for one achievement, from its definition.
     *
     * @param  class-string<BaseAchievementDefinition>  $achievementClass
     * @return array<string, mixed>
     */
    protected static function displayPayload(Model $achiever, string $achievementClass, string $name, string $description): array
    {
        return [
            'key' => $achievementClass::key(),
            'name' => $name,
            'description' => $description,
            'goal' => $achievementClass::goal(),
            'progress' => $achievementClass::getProgress($achiever),
            'progress_percentage' => $achievementClass::getProgressPercentage($achiever),
            'is_completed' => $achievementClass::isCompleted($achiever),
            'is_unlocked' => $achievementClass::isUnlocked($achiever),
            'is_started' => $achievementClass::isStarted($achiever),
            'is_secret' => $achievementClass::isSecret(),
            'is_visible' => $achievementClass::isVisibleTo($achiever),
            'category' => $achievementClass::category(),
            'icon' => $achievementClass::getDisplayIcon($achiever),
            'image_url' => $achievementClass::getDisplayImage($achiever),
        ];
    }

    /**
     * Whether a cached display payload can be served as it is.
     *
     * One cached by 0.2 has no icon or image_url. It counts as a miss and is
     * built again, under the same key, rather than handed back without them
     * until it expires.
     *
     * @phpstan-assert-if-true array<string, mixed> $payload
     */
    protected static function isCurrentDisplay(mixed $payload): bool
    {
        return is_array($payload)
            && array_key_exists('icon', $payload)
            && array_key_exists('image_url', $payload);
    }

    /**
     * Seconds to cache a display payload: half as long until it is unlocked,
     * since progress may still change.
     *
     * @param  array<string, mixed>  $payload
     */
    protected static function getDisplayCacheTtl(array $payload): int
    {
        return $payload['is_unlocked'] ? static::getCacheTtl() : intdiv(static::getCacheTtl(), 2);
    }

    /**
     * Run the callback with the given rows held for the achiever, unless a
     * caller above already holds some.
     *
     * @template TResult
     *
     * @param  array<string, AchieverAchievement|null>  $records
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    protected static function withRecords(Model $achiever, array $records, callable $callback): mixed
    {
        $opened = ! AchievementDefinition::hasRecordsWindow($achiever);

        if ($opened) {
            AchievementDefinition::primeRecords($achiever, $records);
        }

        try {
            return $callback();
        } finally {
            if ($opened) {
                AchievementDefinition::flushRecords($achiever);
            }
        }
    }

    /**
     * Reset discovery (useful for testing)
     */
    public static function resetDiscovery()
    {
        static::$achievements = [];
        static::$images = [];
        static::$discovered = false;
    }

    /**
     * The image folder's files, by path relative to merit.images.path, with
     * their modified times. Read with discovery and cached with it.
     *
     * @return array<string, int>
     */
    public static function getImageIndex(): array
    {
        static::discoverAchievements();

        return static::$images;
    }

    /**
     * Log debug information if debug mode is enabled
     */
    protected static function debug(string $message, array $context = []): void
    {
        if (! config('merit.debug.enabled', false)) {
            return;
        }

        $channel = config('merit.debug.log_channel', 'single');
        $level = config('merit.debug.log_level', 'debug');

        Log::channel($channel)->log($level, "[Merit] {$message}", $context);
    }

    /**
     * Get cache TTL in seconds
     */
    protected static function getCacheTtl(): int
    {
        return config('merit.cache_ttl', 10) * 60; // Convert minutes to seconds
    }

    /**
     * Generate cache key for achievements discovery
     */
    protected static function getDiscoveryCacheKey(): string
    {
        $path = config('merit.achievements_path', app_path('Achievements'));
        $namespace = config('merit.achievements_namespace', 'App\\Achievements\\');

        // The image folder is part of the entry, so it is part of the key; and
        // the leading version means an entry cached by 0.2, which held only
        // the definitions, is never read as one of these.
        return 'merit:achievements:discovery:'.md5(implode('|', [
            'v2', $path, $namespace, (string) config('merit.images.path'),
        ]));
    }

    /**
     * Generate cache key for user achievements
     */
    protected static function getUserCacheKey($achiever): string
    {
        return 'merit:user:'.get_class($achiever).':'.$achiever->getKey();
    }

    /**
     * Generate cache key for achievement display
     */
    protected static function getDisplayCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:display:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for achievement name
     */
    protected static function getNameCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:name:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for achievement description
     */
    protected static function getDescriptionCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:description:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Generate cache key for a stored achievement record
     *
     * Must match BaseAchievementDefinition::getAchievementRecord().
     */
    protected static function getRecordCacheKey($achiever, string $achievementKey): string
    {
        return 'merit:record:'.get_class($achiever).':'.$achiever->getKey().':'.$achievementKey;
    }

    /**
     * Drop every per-achievement cache for one achiever
     */
    protected static function forgetAchievementCaches($achiever, string $achievementKey): void
    {
        Cache::forget(static::getDisplayCacheKey($achiever, $achievementKey));
        Cache::forget(static::getNameCacheKey($achiever, $achievementKey));
        Cache::forget(static::getDescriptionCacheKey($achiever, $achievementKey));
        Cache::forget(static::getRecordCacheKey($achiever, $achievementKey));
    }

    /**
     * Clear one achievement's caches for an achiever, and the achiever's own
     */
    public static function clearAchievementCache($achiever, string $achievementKey): void
    {
        Cache::forget(static::getUserCacheKey($achiever));
        static::forgetAchievementCaches($achiever, $achievementKey);
    }

    /**
     * Clear all caches related to an achiever
     */
    public static function clearUserCache($achiever): void
    {
        $userCacheKey = static::getUserCacheKey($achiever);
        Cache::forget($userCacheKey);

        // Clear display, name, description and record caches for all achievements
        $achievements = static::getAllAchievements();
        foreach ($achievements as $achievementClass) {
            static::forgetAchievementCaches($achiever, $achievementClass::key());
        }

        static::debug('Cleared user achievement caches', [
            'achiever_id' => $achiever->getKey(),
            'achiever_type' => get_class($achiever),
        ]);
    }

    /**
     * Clear discovery cache
     */
    public static function clearDiscoveryCache(): void
    {
        Cache::forget(static::getDiscoveryCacheKey());
        static::$discovered = false;
        static::$achievements = [];
        static::$images = [];

        static::debug('Cleared achievement discovery cache');
    }
}
