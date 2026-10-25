<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Ram64\LaravelMerit\Contracts\HasExperience;
use Ram64\LaravelMerit\Models\Experiencer;

class ExperienceManager
{
    /**
     * Award experience to a model.
     */
    public static function awardExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        return $experiencer->gainExperience($amount, $reason, $metadata);
    }

    /**
     * Remove experience from a model.
     */
    public static function deductExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        return $experiencer->loseExperience($amount, $reason, $metadata);
    }

    /**
     * Set experience to a specific amount.
     */
    public static function setExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        return $experiencer->setExperience($amount, $reason, $metadata);
    }

    /**
     * Get experience data for a model.
     */
    public static function getExperienceData(Model $experiencer): array
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        return $experiencer->getCachedExperienceData();
    }

    /**
     * Get experience logs for a model.
     */
    public static function getExperienceLogs(Model $experiencer, int $limit = 50)
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        return $experiencer->experienceLogs()->limit($limit)->get();
    }

    /**
     * Calculate level for given experience.
     */
    public static function calculateLevel(int $experience): int
    {
        $experiencer = new Experiencer;

        return $experiencer->calculateLevel($experience);
    }

    /**
     * Get experience required for a specific level.
     */
    public static function getExperienceForLevel(int $level): int
    {
        $experiencer = new Experiencer;

        return $experiencer->getExperienceForLevel($level);
    }

    /**
     * Pre-calculate level requirements for dynamic formulas.
     * Returns an array of [level => experience_required] up to maxLevel.
     */
    public static function precalculateLevelRequirements(?int $maxLevel = null): array
    {
        $config = config('merit.experience');
        $requirements = [];

        if ($config['level_calculation'] === 'dynamic') {
            $formula = $config['level_formula'];
            $maxLevel = $maxLevel ?? $config['max_level'] ?? 100; // Use config max_level, fallback to 100

            // Level 1 needs nothing, as in Experiencer::getExperienceForLevel().
            for ($level = 1; $level <= $maxLevel; $level++) {
                $requirements[$level] = $level === 1 ? 0 : static::evaluateFormula($formula, $level);
            }
        } else {
            // For static levels, just return the configured thresholds
            $requirements = $config['level_thresholds'] ?? [];
        }

        return $requirements;
    }

    /**
     * Get level requirements as a cached array.
     * Useful for displaying level progression or validation.
     */
    public static function getCachedLevelRequirements(?int $maxLevel = null): array
    {
        $cacheKey = 'merit:level_requirements:'.($maxLevel ?? 'default');
        $ttl = config('merit.experience.cache_ttl', 10);

        return Cache::remember($cacheKey, now()->addMinutes($ttl), function () use ($maxLevel) {
            return static::precalculateLevelRequirements($maxLevel);
        });
    }

    /**
     * Evaluate a level formula for a specific level.
     */
    protected static function evaluateFormula(string $formula, int $level): int
    {
        // Simple formula evaluation - replace $level with actual value
        $expression = str_replace('$level', (string) $level, $formula);

        // Use eval for simple expressions (could be improved with a proper expression parser)
        return (int) eval("return {$expression};");
    }

    /**
     * Clear experience cache for a model.
     */
    public static function clearCache(Model $experiencer): void
    {
        static::ensureUsesExperiencerTrait($experiencer);

        /** @var Model&HasExperience $experiencer */
        $experiencer->clearExperienceCaches();
    }

    /**
     * Get experience statistics.
     */
    public static function getStatistics(): array
    {
        $table = config('merit.experience.table', 'experiencers');
        $logsTable = config('merit.experience.logs_table', 'experience_logs');

        return [
            'total_experiencers' => DB::table($table)->count(),
            'total_experience_awarded' => DB::table($logsTable)
                ->whereColumn('experience_after', '>', 'experience_before')
                ->sum(DB::raw('experience_after - experience_before')),
            'total_experience_lost' => DB::table($logsTable)
                ->whereColumn('experience_after', '<', 'experience_before')
                ->sum(DB::raw('experience_before - experience_after')),
            'total_level_ups' => DB::table($logsTable)
                ->whereNotNull('level_after')
                ->whereColumn('level_after', '>', 'level_before')
                ->count(),
            'average_level' => DB::table($table)->avg('level'),
            'highest_level' => DB::table($table)->max('level'),
        ];
    }

    /**
     * Bulk award experience to multiple models.
     */
    public static function bulkAwardExperience(array $awards): array
    {
        $results = [];

        foreach ($awards as $award) {
            try {
                $result = static::awardExperience(
                    $award['experiencer'],
                    $award['amount'],
                    $award['reason'] ?? null,
                    $award['metadata'] ?? []
                );
                $results[] = [
                    'experiencer' => $award['experiencer'],
                    'success' => $result,
                    'amount' => $award['amount'],
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'experiencer' => $award['experiencer'],
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * Ensure the model uses the Experiencer trait.
     */
    protected static function ensureUsesExperiencerTrait(Model $experiencer): void
    {
        if (
            ! method_exists($experiencer, 'gainExperience') ||
            ! method_exists($experiencer, 'loseExperience') ||
            ! method_exists($experiencer, 'getExperience')
        ) {
            throw new \InvalidArgumentException('Model must use the Experiencer trait');
        }
    }
}
