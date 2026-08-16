<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Cycle;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Sortable on its own, and sits between the two cyclic achievements
 * alphabetically. A partial sort would hoist it to the front; the documented
 * fallback leaves it where discovery found it.
 */
class CycleStandaloneAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'cycle_standalone';
    }
}
