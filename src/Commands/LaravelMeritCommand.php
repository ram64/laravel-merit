<?php

namespace Ram64\LaravelMerit\Commands;

use Illuminate\Console\Command;

class LaravelMeritCommand extends Command
{
    public $signature = 'laravel-merit';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
