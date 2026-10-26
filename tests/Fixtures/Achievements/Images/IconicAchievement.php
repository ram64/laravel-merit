<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Names its own icon, and an image by a path relative to the image folder.
 */
class IconicAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'iconic';
    }

    public static function icon(?Model $achiever = null): ?string
    {
        return 'tabler-crown';
    }

    public static function image(?Model $achiever = null): ?string
    {
        return 'badges/iconic.png';
    }
}
