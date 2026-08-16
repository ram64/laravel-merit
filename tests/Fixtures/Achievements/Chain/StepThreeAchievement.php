<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain;

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * Third rung of the chain. Names its prerequisite by class, where StepTwo
 * names its own by key — between them the two supported forms are covered.
 *
 * Discovery is alphabetical by filename, so this file is found *before*
 * StepTwoAchievement. Sorting therefore has to move it.
 */
class StepThreeAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'chain_three';
    }

    public static function prerequisite(): string|array|null
    {
        return StepTwoAchievement::class;
    }
}
