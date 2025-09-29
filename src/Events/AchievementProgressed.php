<?php

namespace Ram64\LaravelMerit\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ram64\LaravelMerit\Models\AchieverAchievement;

class AchievementProgressed implements ShouldQueue
{
    use Dispatchable, SerializesModels;

    public $achievement;

    public $achiever;
    public $progress;

    public function __construct(AchieverAchievement $achievement, Model $achiever, int $progress)
    {
        $this->achievement = $achievement;
        $this->achiever = $achiever;
        $this->progress = $progress;
    }
}
