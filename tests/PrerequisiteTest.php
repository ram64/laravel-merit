<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain\StepOneAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain\StepThreeAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Chain\StepTwoAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Diamond\DiamondJoinAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Diamond\DiamondRootAchievement;

it('can always start an achievement with no prerequisite', function () {
    $user = createUser();

    expect(StepOneAchievement::canStart($user))->toBeTrue()
        ->and(DiamondRootAchievement::canStart($user))->toBeTrue();
});

it('blocks an achievement whose only prerequisite is not unlocked', function () {
    $user = createUser();

    expect(StepTwoAchievement::canStart($user))->toBeFalse();
});

it('starts an achievement once its prerequisite key is unlocked', function () {
    $user = createUser();

    storeProgress($user, 'chain_one');

    expect(StepTwoAchievement::canStart($user))->toBeTrue();
});

it('starts an achievement once the prerequisite it named by class is unlocked', function () {
    $user = createUser();

    storeProgress($user, 'chain_two');

    expect(StepThreeAchievement::canStart($user))->toBeTrue();
});

it('requires every prerequisite in an array, not just one', function () {
    $user = createUser();

    // Half-satisfied blocks. Prerequisite arrays are AND, deliberately.
    storeProgress($user, 'diamond_left');

    expect(DiamondJoinAchievement::canStart($user))->toBeFalse();

    storeProgress($user, 'diamond_right');

    expect(DiamondJoinAchievement::canStart($user))->toBeTrue();
});

it('keeps blocking when the other half of the array is the one satisfied', function () {
    $user = createUser();

    storeProgress($user, 'diamond_right');

    expect(DiamondJoinAchievement::canStart($user))->toBeFalse();
});

it('gates on unlocked rather than completed', function () {
    $user = createUser();

    // Goal reached and marked complete, but never unlocked.
    storeProgress($user, 'chain_one', [
        'completed_at' => now(),
        'unlocked_at' => null,
    ]);

    expect(StepTwoAchievement::canStart($user))->toBeFalse();
});

describe('the batched lookup', function () {
    it('reads the unlocked keys once per check, not once per prerequisite', function () {
        useAchievements('Diamond');

        // root has none, left and right have one each, join has two: four
        // exists() calls before this was batched.
        $user = createUser(points: 1);

        expect(prerequisiteQueriesDuring(fn () => AchievementTracker::processCheck($user)))->toBe(1);
    });

    it('holds the count at one however many achievements are checked', function () {
        useAchievements('Chain');

        $user = createUser(points: 1);

        expect(prerequisiteQueriesDuring(fn () => AchievementTracker::processCheck($user)))->toBe(1);
    });

    it('closes the window, so a later unlock is seen', function () {
        useAchievements('Chain');

        $user = createUser(points: 0);

        AchievementTracker::processCheck($user);

        expect(StepTwoAchievement::canStart($user))->toBeFalse();

        storeProgress($user, 'chain_one');

        // Would be false if the check's held set had outlived it.
        expect(StepTwoAchievement::canStart($user))->toBeTrue();
    });

    it('closes the window even when the check throws', function () {
        useAchievements('Chain');

        $user = createUser(points: 1);

        // Blow up mid-pass, after chain_one has been noted as unlocked.
        Event::listen(AchievementUnlocked::class, function () {
            throw new RuntimeException('listener exploded');
        });

        expect(fn () => AchievementTracker::processCheck($user))->toThrow(RuntimeException::class);

        Event::forget(AchievementUnlocked::class);

        // A stale window would still be claiming chain_one is unlocked.
        expect(StepTwoAchievement::canStart($user))->toBeFalse();
    });

    it('still answers from the database when no window is open', function () {
        useAchievements('Chain');

        $user = createUser();

        expect(prerequisiteQueriesDuring(fn () => StepTwoAchievement::canStart($user)))->toBe(1);
    });
});

/**
 * Count the "which achievements has this achiever unlocked" queries.
 */
function prerequisiteQueriesDuring(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return count(array_filter(
        $log,
        fn ($query) => str_contains($query['query'], 'unlocked_at')
            && str_contains($query['query'], 'achievement_key')
    ));
}

it('scopes the prerequisite check to the achiever that owns the row', function () {
    $user = createUser();
    $other = createUser();

    storeProgress($other, 'chain_one');

    expect(StepTwoAchievement::canStart($user))->toBeFalse()
        ->and(StepTwoAchievement::canStart($other))->toBeTrue();
});
