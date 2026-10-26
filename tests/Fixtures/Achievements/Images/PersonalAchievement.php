<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * An icon that depends on the achiever looking at it.
 */
class PersonalAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'personal';
    }

    public static function icon(?Model $achiever = null): ?string
    {
        return (int) $achiever?->getAttribute('points') >= 5 ? 'tabler-star-filled' : 'tabler-star';
    }
}
