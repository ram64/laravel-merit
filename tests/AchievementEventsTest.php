<?php

use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementProgressed;
use Ram64\LaravelMerit\Events\AchievementStarted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;

beforeEach(function () {
    useAchievements('Progress');

    Event::fake([
        AchievementStarted::class,
        AchievementProgressed::class,
        AchievementCompleted::class,
        AchievementUnlocked::class,
    ]);
});

it('fires each achievement event exactly once across a full progression', function () {
    $user = createUser(points: 1);

    // First progress: the row is created at its starting value, so "started"
    // covers it and no separate progressed event is fired.
    AchievementTracker::processCheck($user);

    Event::assertDispatchedTimes(AchievementStarted::class, 1);
    Event::assertDispatchedTimes(AchievementProgressed::class, 0);
    Event::assertDispatchedTimes(AchievementCompleted::class, 0);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 0);

    $user->update(['points' => 2]);
    AchievementTracker::processCheck($user);

    Event::assertDispatchedTimes(AchievementStarted::class, 1);
    Event::assertDispatchedTimes(AchievementProgressed::class, 1);
    Event::assertDispatchedTimes(AchievementCompleted::class, 0);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 0);

    $user->update(['points' => 3]);
    AchievementTracker::processCheck($user);

    Event::assertDispatchedTimes(AchievementStarted::class, 1);
    Event::assertDispatchedTimes(AchievementProgressed::class, 2);
    Event::assertDispatchedTimes(AchievementCompleted::class, 1);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);

    // Nothing left to say.
    AchievementTracker::processCheck($user);

    Event::assertDispatchedTimes(AchievementStarted::class, 1);
    Event::assertDispatchedTimes(AchievementProgressed::class, 2);
    Event::assertDispatchedTimes(AchievementCompleted::class, 1);
    Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
});

it('fires no events when nothing has any progress', function () {
    $user = createUser(points: 0);

    AchievementTracker::processCheck($user);

    Event::assertNotDispatched(AchievementStarted::class);
    Event::assertNotDispatched(AchievementProgressed::class);
});

it('carries the record and the achiever on the started event', function () {
    $user = createUser(points: 1);

    AchievementTracker::processCheck($user);

    Event::assertDispatched(AchievementStarted::class, function (AchievementStarted $event) use ($user) {
        return $event->achievement->achievement_key === 'counting'
            && $event->achievement->progress === 1
            && $event->achiever->is($user);
    });
});

it('reports the new total on the progressed event', function () {
    $user = createUser(points: 1);
    AchievementTracker::processCheck($user);

    $user->update(['points' => 2]);
    AchievementTracker::processCheck($user);

    Event::assertDispatched(AchievementProgressed::class, function (AchievementProgressed $event) {
        return $event->progress === 2;
    });
});

it('fires completed and unlocked together when the goal is reached', function () {
    $user = createUser(points: 3);

    AchievementTracker::processCheck($user);

    Event::assertDispatched(AchievementCompleted::class, function (AchievementCompleted $event) {
        return $event->achievement->completed_at !== null;
    });
    Event::assertDispatched(AchievementUnlocked::class, function (AchievementUnlocked $event) {
        return $event->achievement->unlocked_at !== null;
    });
});

describe('award()', function () {
    it('unlocks straight away and fires the whole sequence', function () {
        $user = createUser();

        $record = AchievementTracker::award($user, 'counting');

        expect($record->progress)->toBe(3)
            ->and($record->unlocked_at)->not->toBeNull()
            ->and(CountingAchievement::isUnlocked($user))->toBeTrue();

        Event::assertDispatchedTimes(AchievementStarted::class, 1);
        Event::assertDispatchedTimes(AchievementProgressed::class, 1);
        Event::assertDispatchedTimes(AchievementCompleted::class, 1);
        Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
    });

    it('is a no-op the second time', function () {
        $user = createUser();

        AchievementTracker::award($user, 'counting');
        AchievementTracker::award($user, 'counting');

        Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
    });

    it('rejects an unknown key', function () {
        AchievementTracker::award(createUser(), 'no_such_achievement');
    })->throws(InvalidArgumentException::class, "Achievement 'no_such_achievement' not found");
});

describe('increment()', function () {
    it('moves progress one step at a time', function () {
        $user = createUser();

        AchievementTracker::increment($user, 'counting');

        expect(CountingAchievement::getProgress($user))->toBe(1);

        Event::assertDispatchedTimes(AchievementStarted::class, 1);
        Event::assertDispatchedTimes(AchievementProgressed::class, 1);
        Event::assertDispatchedTimes(AchievementCompleted::class, 0);
    });

    it('completes once the increments reach the goal', function () {
        $user = createUser();

        AchievementTracker::increment($user, 'counting', 2);
        $record = AchievementTracker::increment($user, 'counting', 2);

        expect($record->progress)->toBe(3);

        Event::assertDispatchedTimes(AchievementProgressed::class, 2);
        Event::assertDispatchedTimes(AchievementCompleted::class, 1);
        Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
    });

    it('refuses to start an achievement whose prerequisites are unmet', function () {
        useAchievements('Chain');

        $user = createUser();

        expect(AchievementTracker::increment($user, 'chain_two'))->toBeNull()
            ->and($user->achievements()->count())->toBe(0);

        Event::assertNotDispatched(AchievementStarted::class);
    });

    it('rejects an unknown key', function () {
        AchievementTracker::increment(createUser(), 'no_such_achievement');
    })->throws(InvalidArgumentException::class, "Achievement 'no_such_achievement' not found");
});
