<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class StepTwoAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'chain_two';
    }

    public static function prerequisite(): string|array|null
    {
        return 'chain_one';
    }
}
