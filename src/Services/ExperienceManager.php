<?php

namespace Ram64\LaravelMerit\Services;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Models\Experiencer;

class ExperienceManager
{
    /**
     * Award experience to a model.
     */
    public function awardExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        return $experiencer->gainExperience($amount, $reason, $metadata);
    }

    /**
     * Remove experience from a model.
     */
    public function deductExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        return $experiencer->loseExperience($amount, $reason, $metadata);
    }

    /**
     * Set experience to a specific amount.
     */
    public function setExperience(Model $experiencer, int $amount, ?string $reason = null, array $metadata = []): bool
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        return $experiencer->setExperience($amount, $reason, $metadata);
    }

    /**
     * Get experience data for a model.
     */
    public function getExperienceData(Model $experiencer): array
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        return $experiencer->getCachedExperienceData();
    }

    /**
     * Get experience logs for a model.
     */
    public function getExperienceLogs(Model $experiencer, int $limit = 50)
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        return $experiencer->experienceLogs()->limit($limit)->get();
    }

    /**
     * Calculate level for given experience.
     */
    public function calculateLevel(int $experience): int
    {
        $experiencer = new Experiencer();
        return $experiencer->calculateLevel($experience);
    }

    /**
     * Get experience required for a specific level.
     */
    public function getExperienceForLevel(int $level): int
    {
        $experiencer = new Experiencer();
        return $experiencer->getExperienceForLevel($level);
    }

    /**
     * Clear experience cache for a model.
     */
    public function clearCache(Model $experiencer): void
    {
        $this->ensureUsesExperiencerTrait($experiencer);
        $experiencer->clearExperienceCaches();
    }

    /**
     * Get experience statistics.
     */
    public function getStatistics(): array
    {
        $table = config('merit.experience.table', 'experiencers');
        $logsTable = config('merit.experience.logs_table', 'experience_logs');

        return [
            'total_experiencers' => \DB::table($table)->count(),
            'total_experience_awarded' => \DB::table($logsTable)->where('event_type', 'gained')->sum('experience_change'),
            'total_experience_lost' => \DB::table($logsTable)->where('event_type', 'lost')->sum(\DB::raw('ABS(experience_change)')),
            'total_level_ups' => \DB::table($logsTable)->where('event_type', 'level_up')->count(),
            'average_level' => \DB::table($table)->avg('level'),
            'highest_level' => \DB::table($table)->max('level'),
        ];
    }

    /**
     * Bulk award experience to multiple models.
     */
    public function bulkAwardExperience(array $awards): array
    {
        $results = [];

        foreach ($awards as $award) {
            try {
                $result = $this->awardExperience(
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
    protected function ensureUsesExperiencerTrait(Model $experiencer): void
    {
        if (!method_exists($experiencer, 'gainExperience') ||
            !method_exists($experiencer, 'loseExperience') ||
            !method_exists($experiencer, 'getExperience')) {
            throw new \InvalidArgumentException('Model must use the Experiencer trait');
        }
    }
}