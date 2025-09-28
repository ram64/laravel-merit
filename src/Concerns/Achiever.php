<?php

namespace Ram64\LaravelMerit\Concerns;

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Models\AchieverAchievement;

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

        Cache::forget($this->cacheKey() ?? "achievements_{$this->id}");
    }
}
