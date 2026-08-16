<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Never makes any progress, so it should never get a row.
 */
class DormantAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'dormant';
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        return 0;
    }
}
