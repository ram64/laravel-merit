<?php

namespace Ram64\LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;

class AchieverAchievement extends Model
{
    protected $table;

    /**
     * Fillable attributes for the model.
     *
     * @var array
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
     * @var array
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
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, AchieverAchievement>
     */
    public function achiever()
    {
        return $this->morphTo();
    }

    /**
     * Dispatch an event, either queued or synchronously.
     *
     * @param  mixed  $event
     * @return void
     */
    protected function dispatchEvent($event)
    {
        if (config('merit.queue_events', false)) {
            dispatch($event);
        } else {
            dispatch_sync($event);
        }
    }

    /**
     * Increment progress towards the achievement goal.
     *
     * @param  mixed  $achiever
     * @return void
     */
    public function incrementProgress(int $amount = 1, ?Model $achiever = null)
    {
        $wasStarted = $this->progress > 0;
        $wasCompleted = $this->status === 'unlocked';

        $this->progress += $amount;
        $this->status = ($this->progress >= $this->goal) ? 'unlocked' : ($this->progress > 0 ? 'in_progress' : 'started');
        $this->progress = min($this->progress, $this->goal);
        if ($this->progress === $this->goal) {
            $this->unlocked_at = now();
        }
        $this->save();

        if (! $wasStarted && $this->progress > 0) {
            $this->dispatchEvent(new \Ram64\LaravelMerit\Events\AchievementStarted($this, $achiever));
        } elseif ($this->progress > 0 && $this->progress < $this->goal) {
            $this->dispatchEvent(new \Ram64\LaravelMerit\Events\AchievementProgressed($this, $achiever, $amount));
        }

        if ($this->progress === $this->goal && ! $wasCompleted) {
            $this->dispatchEvent(new \Ram64\LaravelMerit\Events\AchievementCompleted($this, $achiever));
            $this->dispatchEvent(new \Ram64\LaravelMerit\Events\AchievementUnlocked($this, $achiever));
        }
    }
}
