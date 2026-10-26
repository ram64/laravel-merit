<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * No icon of its own, in a category that may have a default one.
 */
class CategorisedAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'categorised';
    }

    public static function category(): string
    {
        return 'fishing';
    }
}
