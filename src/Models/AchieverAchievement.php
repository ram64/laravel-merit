<?php

namespace Ram64\LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementProgressed;
use Ram64\LaravelMerit\Events\AchievementStarted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;

/**
 * The stored progress for one achievement and one achiever.
 *
 * The columns are declared here rather than inferred: the migrations ship as
 * .php.stub files, so static analysis has no schema to read them from.
 *
 * @property int $id
 * @property int|string $achiever_id
 * @property string $achiever_type
 * @property string $achievement_key
 * @property int $progress
 * @property int $goal
 * @property string $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|string|null $started_at
 * @property Carbon|string|null $completed_at
 * @property Carbon|null $unlocked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AchieverAchievement extends Model
{
    protected $table;

    /**
     * Fillable attributes for the model.
     *
     * @var list<string>
     */
    protected $fillable = [
        'achiever_id',
        'achiever_type',
        'achievement_key',
        'progress',
        'goal',
        'metadata',
        'started_at',
        'completed_at',
        'unlocked_at',

    ];

    /**
     * Casts for the model attributes.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'progress' => 'integer',
        'goal' => 'integer',
        'unlocked_at' => 'datetime',
    ];

    /**
     * Constructor to set the table name from config.
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('merit.achievements_table', 'achiever_achievements');
    }

    /**
     * Get the Achiever for this Achievement.
     *
     * @return MorphTo<Model, $this>
     */
    public function achiever()
    {
        return $this->morphTo();
    }

    /**
     * Dispatch an achievement event.
     *
     * Events go to the event dispatcher, not the command bus: dispatch() and
     * dispatch_sync() treat their argument as a job and look for a handle() on
     * it, which an event does not have. Whether the work happens now or on a
     * queue is a listener concern — a listener marked ShouldQueue is queued by
     * the dispatcher either way.
     *
     * @param  mixed  $event
     * @return void
     */
    protected function dispatchEvent($event)
    {
        event($event);
    }

    /**
     * Increment progress towards the achievement goal.
     *
     * @return void
     */
    public function incrementProgress(int $amount = 1, ?Model $achiever = null)
    {
        // An unlocked row is final, as it is for the tracker's increment().
        // Carrying on re-stamped unlocked_at on every later call. It also
        // re-fired the unlock events on a row the tracker had unlocked, because
        // this used to test the status column, which the tracker never writes.
        if ($this->unlocked_at !== null) {
            return;
        }

        // Every event below requires the achiever. Resolve it from the row
        // when the caller did not pass one, rather than fail with a TypeError
        // after the progress has already been saved.
        $achiever ??= $this->achiever;

        $wasStarted = $this->progress > 0;

        $this->progress += $amount;
        $this->status = ($this->progress >= $this->goal) ? 'unlocked' : ($this->progress > 0 ? 'in_progress' : 'started');
        $this->progress = min($this->progress, $this->goal);
        if ($this->progress === $this->goal) {
            // Both stamps, as the tracker writes them.
            $this->completed_at = now();
            $this->unlocked_at = now();
        }
        $this->save();

        if (! $wasStarted && $this->progress > 0) {
            $this->dispatchEvent(new AchievementStarted($this, $achiever));
        } elseif ($this->progress > 0 && $this->progress < $this->goal) {
            // The new total, as the tracker sends it, not the size of the step.
            $this->dispatchEvent(new AchievementProgressed($this, $achiever, $this->progress));
        }

        if ($this->progress === $this->goal) {
            $this->dispatchEvent(new AchievementCompleted($this, $achiever));
            $this->dispatchEvent(new AchievementUnlocked($this, $achiever));
        }
    }
}
