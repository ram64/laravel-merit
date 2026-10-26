<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * A secret that keeps the default hidden icon (none).
 */
class QuietAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'quiet';
    }

    public static function isSecret(): bool
    {
        return true;
    }

    public static function icon(?Model $achiever = null): ?string
    {
        return 'tabler-moon';
    }
}
