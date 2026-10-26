<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images;

use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * A secret with an icon of its own, and a replacement icon while hidden.
 */
class MysteryAchievement extends FixtureAchievement
{
    public static function key(): string
    {
        return 'mystery';
    }

    public static function isSecret(): bool
    {
        return true;
    }

    public static function icon(?Model $achiever = null): ?string
    {
        return 'tabler-key';
    }

    public static function hiddenIcon(): ?string
    {
        return 'tabler-question-mark';
    }
}
