<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * An image hosted elsewhere, given as a full URL.
 */
class RemoteImageAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'remote';
    }

    public static function image(?Model $achiever = null): ?string
    {
        return 'https://cdn.example.com/badges/remote.webp?size=512';
    }
}
