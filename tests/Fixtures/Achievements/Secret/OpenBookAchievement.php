<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Secret;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class OpenBookAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'open_book';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Open Book';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'Nothing hidden about this one.';
    }
}
