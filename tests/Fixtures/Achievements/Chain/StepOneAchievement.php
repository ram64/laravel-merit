<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

class StepOneAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'chain_one';
    }
}
