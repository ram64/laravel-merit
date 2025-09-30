<?php

namespace Ram64\LaravelMerit\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Ram64\LaravelMerit\Models\ExperienceLog;

class ExperienceGained implements ShouldQueue
{
    use Dispatchable, SerializesModels;

    public $experienceLog;

    public $experiencer;

    public $amount;

    public $reason;

    public function __construct(ExperienceLog $experienceLog, Model $experiencer, int $amount, ?string $reason = null)
    {
        $this->experienceLog = $experienceLog;
        $this->experiencer = $experiencer;
        $this->amount = $amount;
        $this->reason = $reason;
    }
}
