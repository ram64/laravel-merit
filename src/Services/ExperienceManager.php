<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Models\Experiencer;

class ExperienceManager
{
    /**
     * Award experience to a model.
     */
    public static function awardExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);
        return $experiencer->gainExperience($amount, $reason, $metadata);
    }

    /**
     * Remove experience from a model.
     */
    public static function deductExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);
        return $experiencer->loseExperience($amount, $reason, $metadata);
    }

    /**
     * Set experience to a specific amount.
     */
    public static function setExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        static::ensureUsesExperiencerTrait($experiencer);
        return $experiencer->setExperience($amount, $reason, $metadata);
    }

    /**
     * Get experience data for a model.
     */
    public static function getExperienceData(Model $experiencer): array
    {
        static::ensureUsesExperiencerTrait($experiencer);
        return $experiencer->getCachedExperienceData();
    }

    /**
     * Get experience logs for a model.
     */
    public static function getExperienceLogs(Model $experiencer, int $limit = 50)
    {
        static::ensureUsesExperiencerTrait($experiencer);
        return $experiencer->experienceLogs()->limit($limit)->get();
    }

    /**
     * Calculate level for given experience.
     */
    public static function calculateLevel(int $experience): int
    {
        $experiencer = new Experiencer();
        return $experiencer->calculateLevel($experience);
    }

    /**
     * Get experience required for a specific level.
     */
    public static function getExperienceForLevel(int $level): int
    {
        $experiencer = new Experiencer();
        return $experiencer->getExperienceForLevel($level);
    }

    /**
     * Clear experience cache for a model.
     */
    public static function clearCache(Model $experiencer): void
    {
        static::ensureUsesExperiencerTrait($experiencer);
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
            'total_experiencers' => \DB::table($table)->count(),
            'total_experience_awarded' => \DB::table($logsTable)
                ->whereColumn('experience_after', '>', 'experience_before')
                ->sum(\DB::raw('experience_after - experience_before')),
            'total_experience_lost' => \DB::table($logsTable)
                ->whereColumn('experience_after', '<', 'experience_before')
                ->sum(\DB::raw('experience_before - experience_after')),
            'total_level_ups' => \DB::table($logsTable)
                ->whereNotNull('level_after')
                ->whereColumn('level_after', '>', 'level_before')
                ->count(),
            'average_level' => \DB::table($table)->avg('level'),
            'highest_level' => \DB::table($table)->max('level'),
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
            !method_exists($experiencer, 'gainExperience') ||
            !method_exists($experiencer, 'loseExperience') ||
            !method_exists($experiencer, 'getExperience')
        ) {
            throw new \InvalidArgumentException('Model must use the Experiencer trait');
        }
    }
}
