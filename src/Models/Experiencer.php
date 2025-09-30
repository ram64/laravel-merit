<?php

namespace Ram64\LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Experiencer extends Model
{
    protected $table;

    /**
     * Fillable attributes for the model.
     *
     * @var array
     */
    protected $fillable = [
        'experiencer_id',
        'experiencer_type',
        'experience',
        'level',
        'metadata',
    ];

    /**
     * Casts for the model attributes.
     *
     * @var array
     */
    protected $casts = [
        'experience' => 'integer',
        'level' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Constructor to set the table name from config.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('merit.experience.table', 'experiencers');
    }

    /**
     * Get the parent experiencer model.
     */
    public function experiencer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the experience logs for this experiencer.
     */
    public function experienceLogs()
    {
        return $this->hasMany(ExperienceLog::class, 'experiencer_id', 'experiencer_id')
            ->where('experiencer_type', $this->experiencer_type)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get the current level for the given experience.
     */
    public function getCurrentLevelAttribute(): int
    {
        return $this->calculateLevel($this->experience);
    }

    /**
     * Calculate the level for a given experience amount.
     */
    public function calculateLevel(int $experience): int
    {
        $method = config('merit.experience.level_calculation', 'static');

        if ($method === 'dynamic') {
            return $this->calculateDynamicLevel($experience);
        }

        return $this->calculateStaticLevel($experience);
    }

    /**
     * Calculate level using static thresholds.
     */
    protected function calculateStaticLevel(int $experience): int
    {
        $thresholds = config('merit.experience.level_thresholds', []);
        $level = 1;

        foreach ($thresholds as $lvl => $requiredExp) {
            if ($experience >= $requiredExp) {
                $level = $lvl;
            } else {
                break;
            }
        }

        return $level;
    }

    /**
     * Calculate level using dynamic formula.
     */
    protected function calculateDynamicLevel(int $experience): int
    {
        $formula = config('merit.experience.level_formula', '50 * $level * $level');
        $level = 1;

        while (true) {
            $requiredExp = $this->evaluateFormula($formula, $level);
            if ($experience < $requiredExp) {
                break;
            }
            $level++;
        }

        return max(1, $level - 1);
    }

    /**
     * Evaluate the level formula.
     */
    protected function evaluateFormula(string $formula, int $level): int
    {
        // Simple formula evaluation - replace $level with actual value
        $expression = str_replace('$level', $level, $formula);

        // Use eval for simple expressions (could be improved with a proper expression parser)
        return (int) eval("return $expression;");
    }

    /**
     * Get the experience required for the next level.
     */
    public function getExperienceForNextLevel(): int
    {
        $nextLevel = $this->level + 1;
        $method = config('merit.experience.level_calculation', 'static');

        if ($method === 'dynamic') {
            $formula = config('merit.experience.level_formula', '50 * $level * $level');
            return $this->evaluateFormula($formula, $nextLevel);
        }

        $thresholds = config('merit.experience.level_thresholds', []);
        return $thresholds[$nextLevel] ?? PHP_INT_MAX;
    }

    /**
     * Get the experience progress towards the next level.
     */
    public function getProgressToNextLevel(): array
    {
        $currentLevelExp = $this->getExperienceForLevel($this->level);
        $nextLevelExp = $this->getExperienceForNextLevel();

        $progress = $this->experience - $currentLevelExp;
        $required = $nextLevelExp - $currentLevelExp;

        return [
            'current' => $progress,
            'required' => $required,
            'percentage' => $required > 0 ? min(100, ($progress / $required) * 100) : 100,
        ];
    }

    /**
     * Get the experience required for a specific level.
     */
    protected function getExperienceForLevel(int $level): int
    {
        $method = config('merit.experience.level_calculation', 'static');

        if ($method === 'dynamic') {
            $formula = config('merit.experience.level_formula', '50 * $level * $level');
            return $this->evaluateFormula($formula, $level);
        }

        $thresholds = config('merit.experience.level_thresholds', []);
        return $thresholds[$level] ?? 0;
    }
}