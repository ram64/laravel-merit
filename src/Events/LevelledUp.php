<?php

namespace Ram64\LaravelMerit\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ram64\LaravelMerit\Models\ExperienceLog;

class LevelledUp implements ShouldQueue
{
    use Dispatchable, SerializesModels;

    public $experienceLog;

    public $experiencer;

    public $oldLevel;

    public $newLevel;

    public $reason;

    public function __construct(?ExperienceLog $experienceLog, Model $experiencer, int $oldLevel, int $newLevel, ?string $reason = null)
    {
        $this->experienceLog = $experienceLog;
        $this->experiencer = $experiencer;
        $this->oldLevel = $oldLevel;
        $this->newLevel = $newLevel;
        $this->reason = $reason;
    }
}
