<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Display;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Name and description are derived from the achiever, so a stale cache entry
 * is visible: change the achiever, and anything still serving the old string
 * is serving it from cache.
 */
class DynamicTextAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'dynamic_text';
    }

    public static function goal(): int
    {
        return 2;
    }

    public static function name(?Model $achiever = null): string
    {
        return 'Well done, '.($achiever->name ?? 'stranger');
    }

    public static function description(?Model $achiever = null): string
    {
        return 'You are on '.($achiever->points ?? 0).' points';
    }
}
