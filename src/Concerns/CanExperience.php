<?php

namespace Ram64\LaravelMerit\Concerns;

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Events\ExperienceGained;
use Ram64\LaravelMerit\Events\ExperienceLost;
use Ram64\LaravelMerit\Events\LevelledUp;
use Ram64\LaravelMerit\Models\Experiencer;
use Ram64\LaravelMerit\Models\ExperienceLog;

trait CanExperience
{
    /**
     * Get the experiencer record for this model.
     */
    public function experiencer()
    {
        return $this->morphOne(Experiencer::class, 'experiencer');
    }

    /**
     * Get or create the experiencer record.
     */
    protected function getExperiencer(): Experiencer
    {
        return $this->experiencer ?? $this->experiencer()->firstOrCreate([
            'experience' => 0,
            'level' => 1,
        ]);
    }

    /**
     * Get the current experience points.
     */
    public function getExperience(): int
    {
        return $this->getExperiencer()->experience;
    }

    /**
     * Get the current level.
     */
    public function getLevel(): int
    {
        return $this->getExperiencer()->level;
    }

    /**
     * Get experience progress to next level.
     */
    public function getExperienceProgress(): array
    {
        return $this->getExperiencer()->getProgressToNextLevel();
    }

    /**
     * Award experience points to this experiencer.
     */
    public function gainExperience(int $amount, ?string $reason = null, array $metadata = []): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $experiencer = $this->getExperiencer();
        $oldExperience = $experiencer->experience;
        $oldLevel = $experiencer->level;

        // Calculate new experience (can't go below 0)
        $newExperience = max(0, $oldExperience + $amount);
        $newLevel = $experiencer->calculateLevel($newExperience);

        // Update the experiencer
        $experiencer->update([
            'experience' => $newExperience,
            'level' => max($oldLevel, $newLevel), // Levels never decrease
        ]);

        // Log the experience gain if enabled
        $experienceLog = null;
        if (config('merit.experience.logging_enabled', true)) {
            $experienceLog = ExperienceLog::create([
                'experiencer_id' => $this->id,
                'experiencer_type' => get_class($this),
                'experience_before' => $oldExperience,
                'experience_after' => $newExperience,
                'level_before' => $oldLevel,
                'level_after' => $newLevel > $oldLevel ? $newLevel : null,
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
        }

        // Fire the experience gained event
        ExperienceGained::dispatch($experienceLog, $this, $amount, $reason);

        // Check for level up
        if ($newLevel > $oldLevel) {
            $this->handleLevelUp($oldLevel, $newLevel, $reason, $experienceLog);
        }

        // Clear experience caches
        $this->clearExperienceCaches();

        return true;
    }

    /**
     * Remove experience points from this experiencer.
     */
    public function loseExperience(int $amount, ?string $reason = null, array $metadata = []): bool
    {
        if ($amount <= 0) {
            return false;
        }

        $experiencer = $this->getExperiencer();
        $oldExperience = $experiencer->experience;
        $oldLevel = $experiencer->level;

        // Calculate new experience (can't go below 0)
        $newExperience = max(0, $oldExperience - $amount);
        $newLevel = $experiencer->calculateLevel($newExperience);

        // Update the experiencer (levels never decrease)
        $experiencer->update([
            'experience' => $newExperience,
            'level' => $oldLevel, // Levels never decrease
        ]);

        // Log the experience loss if enabled
        $experienceLog = null;
        if (config('merit.experience.logging_enabled', true)) {
            $experienceLog = ExperienceLog::create([
                'experiencer_id' => $this->id,
                'experiencer_type' => get_class($this),
                'experience_before' => $oldExperience,
                'experience_after' => $newExperience,
                'level_before' => $oldLevel,
                'level_after' => null, // No level change on loss
                'reason' => $reason,
                'metadata' => $metadata,
            ]);
        }

        // Fire the experience lost event
        ExperienceLost::dispatch($experienceLog, $this, $amount, $reason);

        // Clear experience caches
        $this->clearExperienceCaches();

        return true;
    }

    /**
     * Set experience to a specific amount.
     */
    public function setExperience(int $amount, ?string $reason = null, array $metadata = []): bool
    {
        $currentExperience = $this->getExperience();

        if ($amount > $currentExperience) {
            return $this->gainExperience($amount - $currentExperience, $reason, $metadata);
        } elseif ($amount < $currentExperience) {
            return $this->loseExperience($currentExperience - $amount, $reason, $metadata);
        }

        return true; // No change needed
    }

    /**
     * Set the level to a specific value, adjusting experience accordingly.
     */
    public function setLevel(int $targetLevel, ?string $reason = null, array $metadata = []): bool
    {
        if ($targetLevel <= 0) {
            return false;
        }

        $experiencer = $this->getExperiencer();
        $currentLevel = $experiencer->level;
        $currentExperience = $experiencer->experience;

        // Check if level decreases are allowed
        $allowDecrease = config('merit.experience.allow_level_decrease', false);

        // If level decreases not allowed and target level is lower, keep current level
        if (!$allowDecrease && $targetLevel < $currentLevel) {
            return true; // No change needed
        }

        // Calculate the minimum experience required for the target level
        $requiredExperience = $experiencer->getExperienceForLevel($targetLevel);

        // For level increases or allowed decreases, ensure we have at least the required experience
        // For natural decreases (not allowed), keep current experience
        if ($allowDecrease || $targetLevel > $currentLevel) {
            $newExperience = max($currentExperience, $requiredExperience);
        } else {
            $newExperience = $currentExperience;
        }

        $newLevel = $targetLevel;

        // Update the experiencer
        $experiencer->update([
            'experience' => $newExperience,
            'level' => $newLevel,
        ]);

        // Log the level change if enabled
        $experienceLog = null;
        if (config('merit.experience.logging_enabled', true)) {
            $experienceLog = ExperienceLog::create([
                'experiencer_id' => $this->id,
                'experiencer_type' => get_class($this),
                'experience_before' => $currentExperience,
                'experience_after' => $newExperience,
                'level_before' => $currentLevel,
                'level_after' => $newLevel > $currentLevel ? $newLevel : null,
                'reason' => $reason,
                'metadata' => array_merge($metadata, [
                    'manual_level_set' => true,
                    'level_decrease_allowed' => $allowDecrease
                ]),
            ]);
        }

        // Fire appropriate events
        if ($newLevel > $currentLevel) {
            // Fire experience gained if experience was increased
            if ($newExperience > $currentExperience) {
                ExperienceGained::dispatch($experienceLog, $this, $newExperience - $currentExperience, $reason);
            }

            // Handle level up
            $this->handleLevelUp($currentLevel, $newLevel, $reason, $experienceLog);
        } elseif ($newLevel < $currentLevel) {
            // Fire experience lost if experience was decreased (forced level decrease)
            if ($newExperience < $currentExperience) {
                ExperienceLost::dispatch($experienceLog, $this, $currentExperience - $newExperience, $reason);
            }

            // Note: No level down event - levels decreasing is administrative
        }

        // Clear experience caches
        $this->clearExperienceCaches();

        return true;
    }

    /**
     * Handle level up logic.
     */
    protected function handleLevelUp(int $oldLevel, int $newLevel, ?string $reason, ?ExperienceLog $experienceLog = null): void
    {
        // Log the level up if enabled
        $levelUpLog = null;
        if (config('merit.experience.logging_enabled', true)) {
            $levelUpLog = ExperienceLog::create([
                'experiencer_id' => $this->id,
                'experiencer_type' => get_class($this),
                'experience_before' => $this->getExperience(),
                'experience_after' => $this->getExperience(),
                'level_before' => $oldLevel,
                'level_after' => $newLevel,
                'reason' => $reason,
                'metadata' => ['level_up' => true],
            ]);
        }

        // Fire the levelled up event
        LevelledUp::dispatch($levelUpLog ?? $experienceLog, $this, $oldLevel, $newLevel, $reason);
    }

    /**
     * Get experience logs for this experiencer.
     */
    public function experienceLogs()
    {
        return ExperienceLog::where('experiencer_id', $this->id)
            ->where('experiencer_type', get_class($this))
            ->orderBy('created_at', 'desc');
    }

    /**
     * Clear all experience-related caches for this experiencer.
     */
    public function clearExperienceCaches(): void
    {
        $cacheKey = $this->experienceCacheKey();
        Cache::forget($cacheKey);
    }

    /**
     * Get the cache key for experience data.
     */
    protected function experienceCacheKey(): string
    {
        return "merit:experience:{$this->id}:" . get_class($this);
    }

    /**
     * Get cached experience data.
     */
    public function getCachedExperienceData(): array
    {
        $cacheKey = $this->experienceCacheKey();
        $ttl = config('merit.experience.cache_ttl', 10);

        return Cache::remember($cacheKey, now()->addMinutes($ttl), function () {
            $experiencer = $this->getExperiencer();

            return [
                'experience' => $experiencer->experience,
                'level' => $experiencer->level,
                'progress' => $experiencer->getProgressToNextLevel(),
                'next_level_exp' => $experiencer->getExperienceForNextLevel(),
            ];
        });
    }
}
