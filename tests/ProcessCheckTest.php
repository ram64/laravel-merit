<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Jobs\ProcessAchievementCheck;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\DormantAchievement;

it('completes an entire prerequisite chain in a single pass', function () {
    useAchievements('Chain');

    $user = createUser(points: 1);

    AchievementTracker::processCheck($user);

    $unlocked = $user->achievements()
        ->whereNotNull('unlocked_at')
        ->pluck('achievement_key')
        ->all();

    expect($unlocked)->toEqualCanonicalizing(['chain_one', 'chain_two', 'chain_three']);
});

it('completes a diamond in a single pass', function () {
    useAchievements('Diamond');

    $user = createUser(points: 1);

    AchievementTracker::processCheck($user);

    expect($user->achievements()->whereNotNull('unlocked_at')->pluck('achievement_key')->all())
        ->toEqualCanonicalizing(['diamond_root', 'diamond_left', 'diamond_right', 'diamond_join']);
});

it('writes no row for an achievement sitting at zero progress', function () {
    useAchievements('Progress');

    $user = createUser(points: 2);

    AchievementTracker::processCheck($user);

    expect($user->achievements()->pluck('achievement_key')->all())->toBe(['counting'])
        ->and(DormantAchievement::isStarted($user))->toBeFalse()
        ->and(DormantAchievement::getProgress($user))->toBe(0);
});

it('writes no row at all when nothing has any progress', function () {
    useAchievements('Progress');

    $user = createUser(points: 0);

    AchievementTracker::processCheck($user);

    expect(AchieverAchievement::count())->toBe(0);
});

it('skips achievements whose prerequisites are unmet, even when they qualify', function () {
    useAchievements('Chain');

    $user = createUser(points: 1);

    // chain_one at its goal but never unlocked, which is the state canStart()
    // gates on. Both later rungs qualify on points and must still be skipped.
    storeProgress($user, 'chain_one', ['completed_at' => now(), 'unlocked_at' => null]);

    AchievementTracker::processCheck($user);

    expect($user->achievements()->pluck('achievement_key')->all())->toBe(['chain_one']);
});

it('caps stored progress at the goal', function () {
    useAchievements('Progress');

    $user = createUser(points: 99);

    AchievementTracker::processCheck($user);

    expect(CountingAchievement::getProgress($user))->toBe(3)
        ->and(CountingAchievement::getProgressPercentage($user))->toBe(100.0);
});

it('records progress without completing when the goal is not reached', function () {
    useAchievements('Progress');

    $user = createUser(points: 1);

    AchievementTracker::processCheck($user);

    $record = $user->achievements()->firstWhere('achievement_key', 'counting');

    expect($record->progress)->toBe(1)
        ->and($record->goal)->toBe(3)
        ->and($record->started_at)->not->toBeNull()
        ->and($record->completed_at)->toBeNull()
        ->and($record->unlocked_at)->toBeNull();
});

it('advances progress across successive checks', function () {
    useAchievements('Progress');

    $user = createUser(points: 1);
    AchievementTracker::processCheck($user);

    $user->update(['points' => 2]);
    AchievementTracker::processCheck($user);

    expect($user->achievements()->firstWhere('achievement_key', 'counting')->progress)->toBe(2);
});

it('marks an achievement completed and unlocked in the same moment', function () {
    useAchievements('Progress');

    $user = createUser(points: 3);

    AchievementTracker::processCheck($user);

    $record = $user->achievements()->firstWhere('achievement_key', 'counting');

    expect($record->completed_at)->not->toBeNull()
        ->and($record->unlocked_at)->not->toBeNull();
});

it('is idempotent when run twice', function () {
    useAchievements('Chain');

    $user = createUser(points: 1);

    AchievementTracker::processCheck($user);

    $columns = ['id', 'achievement_key', 'progress', 'goal', 'completed_at', 'unlocked_at'];
    $before = AchieverAchievement::orderBy('id')->get()->map->only($columns)->all();

    AchievementTracker::processCheck($user);

    $after = AchieverAchievement::orderBy('id')->get()->map->only($columns)->all();

    expect($after)->toEqual($before)
        ->and(AchieverAchievement::count())->toBe(3);
});

it('does not re-fire the unlocked event for an already unlocked achievement', function () {
    useAchievements('Progress');

    $user = createUser(points: 3);

    AchievementTracker::processCheck($user);

    Event::fake([AchievementCompleted::class, AchievementUnlocked::class]);

    AchievementTracker::processCheck($user);

    Event::assertNotDispatched(AchievementCompleted::class);
    Event::assertNotDispatched(AchievementUnlocked::class);
});

it('leaves an unlocked achievement alone when its progress source falls back', function () {
    useAchievements('Progress');

    $user = createUser(points: 3);
    AchievementTracker::processCheck($user);

    $user->update(['points' => 0]);
    AchievementTracker::processCheck($user);

    $record = $user->achievements()->firstWhere('achievement_key', 'counting');

    expect($record->progress)->toBe(3)
        ->and($record->unlocked_at)->not->toBeNull();
});

it('keeps each achiever on their own row', function () {
    useAchievements('Progress');

    $user = createUser(points: 3);
    $other = createUser(points: 1);

    AchievementTracker::processCheck($user);
    AchievementTracker::processCheck($other);

    expect($user->achievements()->firstWhere('achievement_key', 'counting')->progress)->toBe(3)
        ->and($other->achievements()->firstWhere('achievement_key', 'counting')->progress)->toBe(1);
});

describe('check()', function () {
    it('queues a job when queueing is enabled', function () {
        useAchievements('Progress');
        config()->set('merit.queue_achievements', true);

        Queue::fake();

        $user = createUser(points: 3);
        AchievementTracker::check($user);

        Queue::assertPushed(ProcessAchievementCheck::class);
        expect(AchieverAchievement::count())->toBe(0);
    });

    it('sends the job to the configured connection and queue', function () {
        useAchievements('Progress');
        config()->set('merit.queue_achievements', true);
        config()->set('merit.queue_connection', 'redis');
        config()->set('merit.queue_name', 'achievements');

        Queue::fake();

        AchievementTracker::check(createUser());

        Queue::assertPushed(ProcessAchievementCheck::class, fn ($job) => $job->connection === 'redis' && $job->queue === 'achievements');
    });

    it('processes inline when queueing is disabled', function () {
        useAchievements('Progress');
        config()->set('merit.queue_achievements', false);

        $user = createUser(points: 3);
        AchievementTracker::check($user);

        expect(CountingAchievement::isUnlocked($user))->toBeTrue();
    });

    it('processes the check when the queued job runs', function () {
        useAchievements('Progress');

        $user = createUser(points: 3);

        (new ProcessAchievementCheck($user))->handle();

        expect(CountingAchievement::isUnlocked($user))->toBeTrue();
    });
});
