<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Secret;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class HiddenGemAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'hidden_gem';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Hidden Gem';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Found the thing nobody told you about.';
    }

    public static function isSecret(): bool
    {
        return true;
    }
}
