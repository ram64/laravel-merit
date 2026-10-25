<?php

namespace Ram64\LaravelMerit\Tests\Fixtures\Achievements\Race;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\FixtureAchievement;

/**
 * A multi-step achievement that counts how often its progress is worked out,
 * and can run a hook while it does.
 *
 * The hook stands in for another worker: a check reads the achiever's rows
 * before computing progress, so whatever the hook writes lands between this
 * check's read and its write.
 */
class RacingAchievement extends FixtureAchievement
{
    public static int $computed = 0;

    public static ?Closure $whileComputing = null;

    public static function key(): string
    {
        return 'racing';
    }

    public static function goal(): int
    {
        return 3;
    }

    public static function calculateCurrentProgress(Model $achiever): int
    {
        static::$computed++;

        if (static::$whileComputing !== null) {
            (static::$whileComputing)($achiever);
        }

        return parent::calculateCurrentProgress($achiever);
    }

    public static function reset(): void
    {
        static::$computed = 0;
        static::$whileComputing = null;
    }
}
