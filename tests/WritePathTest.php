<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Race\RacingAchievement;

/**
 * The shape of what the tracker writes, and what a check costs.
 */
beforeEach(fn () => RacingAchievement::reset());
afterEach(fn () => RacingAchievement::reset());

/**
 * Every query run during the callback.
 *
 * @return array<int, string>
 */
function queriesDuring(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_column($log, 'query');
}

describe('cost', function () {
    it('costs one query for an achiever who has unlocked everything', function () {
        useAchievements('Chain');

        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);

        expect(queriesDuring(fn () => AchievementTracker::processCheck($user)))->toHaveCount(1);
    });

    it('does not work out progress for an achievement already unlocked', function () {
        useAchievements('Race');

        $user = createUser(points: 3);
        AchievementTracker::processCheck($user);
        RacingAchievement::reset();

        AchievementTracker::processCheck($user);

        expect(RacingAchievement::$computed)->toBe(0);
    });

    it('reads an existing row from the batch rather than one query apiece', function () {
        useAchievements('Batch');

        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);
        $user->achievements()->update(['unlocked_at' => null, 'completed_at' => null, 'progress' => 0]);

        $selects = array_filter(
            queriesDuring(fn () => AchievementTracker::processCheck($user)),
            fn (string $query) => str_starts_with($query, 'select')
        );

        expect($selects)->toHaveCount(1);
    });
});

describe('racing checks', function () {
    it('announces an unlock once when another check unlocks the row first', function () {
        useAchievements('Race');

        $user = createUser(points: 2);
        AchievementTracker::processCheck($user);

        // Another worker finishes the same achievement while this check is
        // between reading the row and writing it.
        RacingAchievement::$whileComputing = function () use ($user) {
            $user->achievements()->where('achievement_key', 'racing')->update([
                'progress' => 3,
                'completed_at' => now()->subMinute(),
                'unlocked_at' => now()->subMinute(),
            ]);
        };
        $unlockedAt = now()->subMinute()->toDateTimeString();

        Event::fake([AchievementCompleted::class, AchievementUnlocked::class]);

        $user->update(['points' => 3]);
        AchievementTracker::processCheck($user);

        Event::assertNotDispatched(AchievementCompleted::class);
        Event::assertNotDispatched(AchievementUnlocked::class);
        expect($user->achievements()->first()->unlocked_at->toDateTimeString())->toBe($unlockedAt);
    });
});

describe('row shape', function () {
    beforeEach(fn () => useAchievements('Progress'));

    it('writes a status that matches the progress on every path', function () {
        $user = createUser(points: 1);

        AchievementTracker::processCheck($user);
        expect($user->achievements()->firstWhere('achievement_key', 'counting')->status)->toBe('in_progress');

        $user->update(['points' => 3]);
        AchievementTracker::processCheck($user);
        expect($user->achievements()->firstWhere('achievement_key', 'counting')->status)->toBe('unlocked');

        $other = createUser();
        AchievementTracker::increment($other, 'counting');
        expect($other->achievements()->firstWhere('achievement_key', 'counting')->status)->toBe('in_progress');

        AchievementTracker::award($other, 'counting');
        expect($other->achievements()->firstWhere('achievement_key', 'counting')->status)->toBe('unlocked');
    });

    it("moves a row to the definition's current goal", function () {
        $user = createUser(points: 1);
        storeProgress($user, 'counting', [
            'progress' => 2,
            'goal' => 10,
            'completed_at' => null,
            'unlocked_at' => null,
        ]);

        // The definition's goal is 3: two steps in, the row is one short of
        // it, not eight.
        $user->update(['points' => 3]);
        AchievementTracker::processCheck($user);

        $record = $user->achievements()->first();

        expect($record->goal)->toBe(CountingAchievement::goal())
            ->and($record->unlocked_at)->not->toBeNull();
    });

    it('fires the unlock only once the unlock is stored and readable', function () {
        $user = createUser(points: 3);
        $seen = null;

        Event::listen(AchievementUnlocked::class, function (AchievementUnlocked $event) use (&$seen) {
            $seen = CountingAchievement::isUnlocked($event->achiever);
        });

        AchievementTracker::processCheck($user);

        expect($seen)->toBeTrue();
    });
});
