<?php

namespace LaravelMerit\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;
use LaravelMerit\Models\AchieverAchievement;

class AchievementStarted implements ShouldQueue
{
    use SerializesModels;
    public $achievement;
    public $achiever;
    public function __construct(AchieverAchievement $achievement, $achiever)
    {
        $this->achievement = $achievement;
        $this->achiever = $achiever;
    }
}
