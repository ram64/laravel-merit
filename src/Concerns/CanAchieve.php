<?php

namespace Ram64\LaravelMerit\Concerns;

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Services\AchievementTracker;

trait CanAchieve
{
    public function achievements()
    {
        return $this->morphMany(AchieverAchievement::class, 'achiever');
    }

    public function unlockOrProgress(string $achievementClass, int $amount = 1, array $metadata = [], ?int $goal = null)
    {
        // The goal defaults to the definition's own. A fixed default of 1
        // unlocked a multi-step achievement on its first step.
        $achievement = $this->achievements()->firstOrCreate(
            ['achievement_key' => $achievementClass::key()],
            ['metadata' => $metadata, 'progress' => 0, 'goal' => $goal ?? $achievementClass::goal(), 'status' => 'started']
        );

        $achievement->incrementProgress($amount, $this);

        // Clear all Merit caches for this user
        $this->clearAchievementCaches();
    }

    /**
     * Clear all achievement-related caches for this achiever
     */
    public function clearAchievementCaches()
    {
        // Clear the new comprehensive caches
        AchievementTracker::clearUserCache($this);

        // Keep backward compatibility with custom cache keys. Achiever models
        // are not required to define cacheKey(), and calling it blind would
        // fall through Model::__call and throw.
        $customCacheKey = method_exists($this, 'cacheKey') ? $this->cacheKey() : null;

        Cache::forget($customCacheKey ?? "achievements_{$this->getKey()}");
    }

    /**
     * Get cached achievement display data
     */
    public function getCachedAchievementDisplay(string $achievementKey): array
    {
        return AchievementTracker::getAchievementDisplay($this, $achievementKey);
    }

    /**
     * Get all visible achievements for this achiever
     */
    public function getVisibleAchievements(): array
    {
        return AchievementTracker::getVisibleAchievements($this);
    }
}
