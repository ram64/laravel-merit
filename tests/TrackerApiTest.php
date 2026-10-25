<?php

use Illuminate\Support\Facades\Event;
use Ram64\LaravelMerit\Events\AchievementCompleted;
use Ram64\LaravelMerit\Events\AchievementProgressed;
use Ram64\LaravelMerit\Events\AchievementStarted;
use Ram64\LaravelMerit\Events\AchievementUnlocked;
use Ram64\LaravelMerit\Models\AchieverAchievement;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;

beforeEach(fn () => useAchievements('Progress'));

describe('lookups', function () {
    it('resolves an achievement class by key', function () {
        expect(AchievementTracker::getAchievement('counting'))->toBe(CountingAchievement::class);
    });

    it('returns null for an unknown key', function () {
        expect(AchievementTracker::getAchievement('no_such_achievement'))->toBeNull()
            ->and(AchievementTracker::getDefinition('no_such_achievement'))->toBeNull();
    });

    it('builds a definition instance from a key', function () {
        expect(AchievementTracker::getDefinition('counting'))->toBeInstanceOf(CountingAchievement::class);
    });

    it('builds a definition instance from a stored row', function () {
        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);

        $record = AchieverAchievement::first();

        expect(AchievementTracker::getDefinitionFromAchievement($record))
            ->toBeInstanceOf(CountingAchievement::class);
    });

    it('leaves a row orphaned rather than broken when its definition is gone', function () {
        $user = createUser();

        storeProgress($user, 'deleted_achievement');

        $record = AchieverAchievement::first();

        expect(AchievementTracker::getDefinitionFromAchievement($record))->toBeNull()
            ->and($record->achievement_key)->toBe('deleted_achievement');
    });
});

describe('read side', function () {
    it('reports an untouched achievement as unstarted and empty', function () {
        $user = createUser();

        expect(CountingAchievement::getProgress($user))->toBe(0)
            ->and(CountingAchievement::isStarted($user))->toBeFalse()
            ->and(CountingAchievement::isCompleted($user))->toBeFalse()
            ->and(CountingAchievement::isUnlocked($user))->toBeFalse()
            ->and(CountingAchievement::getProgressPercentage($user))->toBe(0.0)
            ->and(CountingAchievement::getRemainingProgress($user))->toBe(3);
    });

    it('reports progress partway through', function () {
        $user = createUser(points: 2);
        AchievementTracker::processCheck($user);

        expect(CountingAchievement::getProgress($user))->toBe(2)
            ->and(CountingAchievement::isStarted($user))->toBeTrue()
            ->and(CountingAchievement::isCompleted($user))->toBeFalse()
            ->and(CountingAchievement::getProgressPercentage($user))->toBeGreaterThan(66.0)
            ->and(CountingAchievement::getRemainingProgress($user))->toBe(1);
    });

    it('assembles the full display payload', function () {
        $user = createUser(points: 3);
        AchievementTracker::processCheck($user);

        expect(AchievementTracker::getAchievementDisplay($user, 'counting'))
            ->toMatchArray([
                'key' => 'counting',
                'name' => 'counting name',
                'description' => 'counting description',
                'goal' => 3,
                'progress' => 3,
                'progress_percentage' => 100.0,
                'is_completed' => true,
                'is_unlocked' => true,
                'is_started' => true,
                'is_secret' => false,
                'is_visible' => true,
                'category' => 'progression',
            ]);
    });
});

describe('the CanAchieve trait', function () {
    it('exposes the achiever own rows', function () {
        $user = createUser(points: 1);
        $other = createUser(points: 1);

        AchievementTracker::processCheck($user);

        expect($user->achievements()->count())->toBe(1)
            ->and($other->achievements()->count())->toBe(0);
    });

    it('reads the display payload through the trait', function () {
        $user = createUser(points: 3);
        AchievementTracker::processCheck($user);

        expect($user->getCachedAchievementDisplay('counting')['is_unlocked'])->toBeTrue();
    });

    it('lists the visible achievements through the trait', function () {
        expect(achievementKeys(createUser()->getVisibleAchievements()))
            ->toEqualCanonicalizing(['counting', 'dormant']);
    });

    it('clears its caches without needing the model to define a cache key', function () {
        $user = createUser();

        $user->clearAchievementCaches();
    })->throwsNoExceptions();
});

describe('unlockOrProgress()', function () {
    it('opens a row and moves it one step', function () {
        Event::fake([AchievementStarted::class, AchievementProgressed::class]);

        $user = createUser();

        $user->unlockOrProgress(CountingAchievement::class, 1, ['source' => 'test'], 3);

        $record = $user->achievements()->firstWhere('achievement_key', 'counting');

        expect($record->progress)->toBe(1)
            ->and($record->goal)->toBe(3)
            ->and($record->status)->toBe('in_progress')
            ->and($record->metadata)->toBe(['source' => 'test'])
            ->and($record->unlocked_at)->toBeNull();

        Event::assertDispatchedTimes(AchievementStarted::class, 1);
        Event::assertDispatchedTimes(AchievementProgressed::class, 0);
    });

    it('progresses an open row without restarting it', function () {
        Event::fake([AchievementStarted::class, AchievementProgressed::class]);

        $user = createUser();

        $user->unlockOrProgress(CountingAchievement::class, 1, [], 3);
        $user->unlockOrProgress(CountingAchievement::class, 1, [], 3);

        expect($user->achievements()->firstWhere('achievement_key', 'counting')->progress)->toBe(2);

        Event::assertDispatchedTimes(AchievementStarted::class, 1);
        Event::assertDispatchedTimes(AchievementProgressed::class, 1);
    });

    it('unlocks once the increments reach the goal', function () {
        Event::fake([AchievementCompleted::class, AchievementUnlocked::class]);

        $user = createUser();

        $user->unlockOrProgress(CountingAchievement::class, 3, [], 3);

        $record = $user->achievements()->firstWhere('achievement_key', 'counting');

        expect($record->progress)->toBe(3)
            ->and($record->status)->toBe('unlocked')
            ->and($record->unlocked_at)->not->toBeNull();

        Event::assertDispatchedTimes(AchievementCompleted::class, 1);
        Event::assertDispatchedTimes(AchievementUnlocked::class, 1);
    });

    it('does not overshoot the goal', function () {
        $user = createUser();

        $user->unlockOrProgress(CountingAchievement::class, 99, [], 3);

        expect($user->achievements()->firstWhere('achievement_key', 'counting')->progress)->toBe(3);
    });
});

describe('incrementProgress()', function () {
    it('resolves the achiever from the row when none is passed', function () {
        Event::fake([AchievementStarted::class]);

        $user = createUser();
        $record = $user->achievements()->create(['achievement_key' => 'counting', 'goal' => 3]);

        $record->incrementProgress();

        Event::assertDispatched(AchievementStarted::class, fn ($event) => $event->achiever->is($user));
    });

    it('stamps completed_at alongside unlocked_at, as the tracker does', function () {
        $user = createUser();

        $user->unlockOrProgress(CountingAchievement::class, 3, [], 3);

        $record = $user->achievements()->firstWhere('achievement_key', 'counting');

        expect($record->completed_at)->not->toBeNull()
            ->and($record->unlocked_at)->not->toBeNull();
    });

    it('leaves the unlock time alone on later calls', function () {
        $user = createUser();

        $this->travelTo(now()->subDay());
        $user->unlockOrProgress(CountingAchievement::class, 3, [], 3);
        $unlockedAt = $user->achievements()->first()->unlocked_at->toDateTimeString();
        $this->travelBack();

        $user->unlockOrProgress(CountingAchievement::class, 1, [], 3);

        expect($user->achievements()->first()->unlocked_at->toDateTimeString())->toBe($unlockedAt);
    });

    it('does not unlock a second time a row the tracker already unlocked', function () {
        $user = createUser();
        AchievementTracker::award($user, 'counting');

        Event::fake([AchievementCompleted::class, AchievementUnlocked::class]);

        $user->unlockOrProgress(CountingAchievement::class, 1, [], 3);

        Event::assertNotDispatched(AchievementCompleted::class);
        Event::assertNotDispatched(AchievementUnlocked::class);
    });
});
