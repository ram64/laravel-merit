<?php

namespace Ram64\LaravelMerit\Concerns;

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Services\AchievementTracker;

trait Achiever
{
    public function achievements()
    {
        return $this->morphMany(AchieverAchievement::class, 'achiever');
    }

    public function unlockOrProgress(string $achievementClass, int $amount = 1, array $metadata = [], int $goal = 1)
    {
        $achievement = $this->achievements()->firstOrCreate(
            ['achievement_key' => $achievementClass::key()],
            ['metadata' => $metadata, 'progress' => 0, 'goal' => $goal, 'status' => 'started']
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

        // Keep backward compatibility with custom cache keys
        Cache::forget($this->cacheKey() ?? "achievements_{$this->id}");
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
