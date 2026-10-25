<?php

use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Models\User;
use Ram64\LaravelMerit\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * Point discovery at a single fixture group.
 *
 * Discovery globs the filesystem, so each group is a real directory of real
 * definition classes under a namespace that mirrors it. Pointing at one group
 * at a time keeps a test's achievement set to exactly what it asked for.
 */
function useAchievements(string $group): void
{
    config()->set(
        'merit.achievements_namespace',
        'Ram64\\LaravelMerit\\Tests\\Fixtures\\Achievements\\'.$group.'\\'
    );
    config()->set(
        'merit.achievements_path',
        __DIR__.'/Fixtures/Achievements/'.$group
    );

    AchievementTracker::clearDiscoveryCache();
}

function createUser(int $points = 0, string $name = 'Test Achiever'): User
{
    return User::create(['name' => $name, 'points' => $points]);
}

/**
 * Map a list of achievement classes to their keys.
 *
 * @param  array<int|string, class-string>  $achievements
 * @return array<int, string>
 */
function achievementKeys(array $achievements): array
{
    return array_values(array_map(fn (string $class) => $class::key(), $achievements));
}

/**
 * Write a progress row directly, bypassing the tracker.
 *
 * Used to set up prerequisite state without relying on the code under test.
 *
 * @param  array<model-property<AchieverAchievement>, mixed>  $attributes
 */
function storeProgress(User $user, string $key, array $attributes = []): AchieverAchievement
{
    return AchieverAchievement::create(array_merge([
        'achiever_id' => $user->id,
        'achiever_type' => $user::class,
        'achievement_key' => $key,
        'progress' => 1,
        'goal' => 1,
        'started_at' => now(),
        'completed_at' => now(),
        'unlocked_at' => now(),
    ], $attributes));
}

/**
 * The position of each key in a list of achievement classes.
 *
 * @param  array<int|string, class-string>  $achievements
 * @return array<string, int>
 */
function achievementPositions(array $achievements): array
{
    return array_flip(achievementKeys($achievements));
}
