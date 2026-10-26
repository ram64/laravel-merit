<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Overrides nothing: no icon, and an image only by the naming convention.
 */
class PlainImageAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'plain';
    }
}
