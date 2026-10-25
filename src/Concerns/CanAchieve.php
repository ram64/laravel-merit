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

        // Only this achievement changed. Clearing every achievement's caches
        // here cost four cache deletes per defined achievement on every call.
        AchievementTracker::clearAchievementCache($this, $achievementClass::key());
        $this->forgetCustomAchievementCache();
    }

    /**
     * Clear all achievement-related caches for this achiever
     */
    public function clearAchievementCaches()
    {
        // Clear the new comprehensive caches
        AchievementTracker::clearUserCache($this);

        $this->forgetCustomAchievementCache();
    }

    /**
     * Keep backward compatibility with custom cache keys. Achiever models are
     * not required to define cacheKey(), and calling it blind would fall
     * through Model::__call and throw.
     */
    protected function forgetCustomAchievementCache(): void
    {
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
