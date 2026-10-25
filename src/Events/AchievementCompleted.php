<?php

namespace Ram64\LaravelMerit\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ram64\LaravelMerit\Models\AchieverAchievement;

class AchievementCompleted
{
    use Dispatchable, SerializesModels;

    public $achievement;

    public $achiever;

    public function __construct(AchieverAchievement $achievement, Model $achiever)
    {
        $this->achievement = $achievement;
        $this->achiever = $achiever;
    }
}
