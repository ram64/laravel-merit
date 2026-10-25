<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Bare;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Definitions\Achievements\BaseAchievementDefinition;

/**
 * A definition extending the base class directly rather than
 * AchievementDefinition. Discovery accepts it, so a check has to handle it.
 */
class BareAchievement extends BaseAchievementDefinition
{
    public static function key(): string
    {
        return 'bare';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Bare';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Extends the base class directly';
    }

    public static function goal(): int
    {
        return 1;
    }

    public static function qualifiesFor(Model $achiever): bool
    {
        return (int) $achiever->getAttribute('points') >= 1;
    }
}
