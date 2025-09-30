<?php

namespace Ram64\LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ExperienceLog extends Model
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
        'experience_change',
        'experience_before',
        'experience_after',
        'level_before',
        'level_after',
        'reason',
        'metadata',
        'event_type',
    ];

    /**
     * Casts for the model attributes.
     *
     * @var array
     */
    protected $casts = [
        'experience_change' => 'integer',
        'experience_before' => 'integer',
        'experience_after' => 'integer',
        'level_before' => 'integer',
        'level_after' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * Constructor to set the table name from config.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('merit.experience.logs_table', 'experience_logs');
    }

    /**
     * Get the parent experiencer model.
     */
    public function experiencer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope for experience gained events.
     */
    public function scopeGained($query)
    {
        return $query->where('event_type', 'gained');
    }

    /**
     * Scope for experience lost events.
     */
    public function scopeLost($query)
    {
        return $query->where('event_type', 'lost');
    }

    /**
     * Scope for level up events.
     */
    public function scopeLevelUps($query)
    {
        return $query->where('event_type', 'level_up');
    }

    /**
     * Check if this log entry represents experience gain.
     */
    public function isGain(): bool
    {
        return $this->event_type === 'gained';
    }

    /**
     * Check if this log entry represents experience loss.
     */
    public function isLoss(): bool
    {
        return $this->event_type === 'lost';
    }

    /**
     * Check if this log entry represents a level up.
     */
    public function isLevelUp(): bool
    {
        return $this->event_type === 'level_up';
    }
}
