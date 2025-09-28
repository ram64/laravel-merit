<?php

namespace LaravelMerit\Achievements;

abstract class BaseAchievement
{
    abstract public static function key(): string;
    abstract public static function name(): string;
    abstract public static function description(): string;
    public static function goal(): int
    {
        return 1;
    }
    public static function isHidden(): bool
    {
        return false;
    }
    abstract public static function qualifies($achiever): bool;
}
