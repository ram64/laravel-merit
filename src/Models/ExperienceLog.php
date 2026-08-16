<?php

namespace Ram64\LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One recorded experience or level change.
 *
 * Columns are declared here because the migrations ship as .php.stub files,
 * so static analysis has no schema to read them from.
 *
 * @property int $id
 * @property int|string $experiencer_id
 * @property string $experiencer_type
 * @property int $experience_before
 * @property int $experience_after
 * @property int $level_before
 * @property int|null $level_after
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read int $experience_change
 * @property-read string $event_type
 */
class ExperienceLog extends Model
{
    protected $table;

    /**
     * Fillable attributes for the model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'experiencer_id',
        'experiencer_type',
        'experience_before',
        'experience_after',
        'level_before',
        'level_after',
        'reason',
        'metadata',
    ];

    /**
     * Casts for the model attributes.
     *
     * @var array<string, string>
     */
    protected $casts = [
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
        return $query->whereColumn('experience_after', '>', 'experience_before');
    }

    /**
     * Scope for experience lost events.
     */
    public function scopeLost($query)
    {
        return $query->whereColumn('experience_after', '<', 'experience_before');
    }

    /**
     * Scope for level up events.
     */
    public function scopeLevelUps($query)
    {
        return $query->whereNotNull('level_after')
            ->whereColumn('level_after', '>', 'level_before');
    }

    /**
     * Get the experience change amount (calculated from before/after).
     */
    public function getExperienceChangeAttribute(): int
    {
        return $this->experience_after - $this->experience_before;
    }

    /**
     * Check if this log entry represents experience gain.
     */
    public function isGain(): bool
    {
        return $this->experience_after > $this->experience_before;
    }

    /**
     * Check if this log entry represents experience loss.
     */
    public function isLoss(): bool
    {
        return $this->experience_after < $this->experience_before;
    }

    /**
     * Check if this log entry represents a level up.
     */
    public function isLevelUp(): bool
    {
        return $this->level_after !== null && $this->level_after > $this->level_before;
    }

    /**
     * Get the inferred event type.
     */
    public function getEventTypeAttribute(): string
    {
        if ($this->isLevelUp()) {
            return 'level_up';
        }

        return $this->isGain() ? 'gained' : 'lost';
    }
}
