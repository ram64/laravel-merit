<?php

namespace Ram64\LaravelMerit\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaravelMerit\Services\AchievementTracker;

class ProcessAchievementCheck implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $achiever;

    public function __construct($achiever)
    {
        $this->achiever = $achiever;

        $connection = config('merit.queue_connection');
        $queue = config('merit.queue_name');

        if ($connection) {
            $this->onConnection($connection);
        }

        if ($queue) {
            $this->onQueue($queue);
        }
    }

    public function handle()
    {
        AchievementTracker::processCheck($this->achiever);
    }
}
