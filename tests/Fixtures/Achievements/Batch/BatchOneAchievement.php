<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Batch;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Text derived from the achiever, so "identical to the singular methods" is
 * an assertion about real per-achiever content rather than a constant.
 */
class BatchOneAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'batch_one';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'One for '.($achiever->name ?? 'stranger');
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Sitting on '.($achiever->points ?? 0).' points';
    }
}
