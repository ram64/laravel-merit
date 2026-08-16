<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Batch;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class BatchSecretAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'batch_secret';
    }

    public static function name(?Model $achiever = null): string
    {
        return 'The Quiet One';
    }

    public static function description(?Model $achiever = null): string
    {
        return 'You found the thing nobody mentions.';
    }

    public static function isSecret(): bool
    {
        return true;
    }
}
