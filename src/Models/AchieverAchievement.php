<?php

namespace LaravelMerit\Models;

use Illuminate\Database\Eloquent\Model;

class AchieverAchievement extends Model
{
    protected $table;

    protected $fillable = [
        'achievement_key',
        'metadata',
        'progress',
        'goal',
        'status',
        'unlocked_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'progress' => 'integer',
        'goal' => 'integer',
        'unlocked_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('merit.achievements_table', 'achiever_achievements');
    }

    public function achiever()
    {
        return $this->morphTo();
    }

    protected function dispatchEvent($event)
    {
        if (config('merit.queue_events', false)) {
            dispatch($event);
        } else {
            dispatch_sync($event);
        }
    }

    public function incrementProgress(int $amount = 1, $achiever = null)
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
            $this->dispatchEvent(new \LaravelMerit\Events\AchievementStarted($this, $achiever));
        } elseif ($this->progress > 0 && $this->progress < $this->goal) {
            $this->dispatchEvent(new \LaravelMerit\Events\AchievementProgressed($this, $achiever, $amount));
        }

        if ($this->progress === $this->goal && ! $wasCompleted) {
            $this->dispatchEvent(new \LaravelMerit\Events\AchievementCompleted($this, $achiever));
            $this->dispatchEvent(new \LaravelMerit\Events\AchievementUnlocked($this, $achiever));
        }
    }
}
