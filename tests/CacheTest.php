<?php

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Display\DynamicTextAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;

function nameKey($user, string $key): string
{
    return 'merit:name:'.$user::class.':'.$user->id.':'.$key;
}

function descriptionKey($user, string $key): string
{
    return 'merit:description:'.$user::class.':'.$user->id.':'.$key;
}

function recordKey($user, string $key): string
{
    return 'merit:record:'.$user::class.':'.$user->id.':'.$key;
}

function displayKey($user, string $key): string
{
    return 'merit:display:'.$user::class.':'.$user->id.':'.$key;
}

describe('cache keys', function () {
    beforeEach(fn () => useAchievements('Display'));

    it('stores the name under the documented key', function () {
        $user = createUser();

        DynamicTextAchievement::getCachedName($user);

        expect(Cache::get(nameKey($user, 'dynamic_text')))->toBe('Well done, Test Achiever');
    });

    it('stores the description under the documented key', function () {
        $user = createUser();

        DynamicTextAchievement::getCachedDescription($user);

        expect(Cache::get(descriptionKey($user, 'dynamic_text')))->toBe('You are on 0 points');
    });

    it('keys the caches per achiever', function () {
        $first = createUser(name: 'First');
        $second = createUser(name: 'Second');

        DynamicTextAchievement::getCachedName($first);
        DynamicTextAchievement::getCachedName($second);

        expect(Cache::get(nameKey($first, 'dynamic_text')))->toBe('Well done, First')
            ->and(Cache::get(nameKey($second, 'dynamic_text')))->toBe('Well done, Second');
    });
});

describe('reuse within the TTL', function () {
    beforeEach(fn () => useAchievements('Display'));

    it('serves a cached name rather than recomputing it', function () {
        $user = createUser();

        expect(DynamicTextAchievement::getCachedName($user))->toBe('Well done, Test Achiever');

        $user->update(['name' => 'Renamed']);

        expect(DynamicTextAchievement::getCachedName($user))->toBe('Well done, Test Achiever');
    });

    it('serves a cached description rather than recomputing it', function () {
        $user = createUser();

        expect(DynamicTextAchievement::getCachedDescription($user))->toBe('You are on 0 points');

        $user->update(['points' => 1]);

        expect(DynamicTextAchievement::getCachedDescription($user))->toBe('You are on 0 points');
    });

    it('does not cache anything when there is no achiever to key on', function () {
        expect(DynamicTextAchievement::getCachedName())->toBe('Well done, stranger')
            ->and(DynamicTextAchievement::getCachedDescription())->toBe('You are on 0 points');
    });

    it('serves a cached display payload rather than recomputing it', function () {
        $user = createUser();

        expect(AchievementTracker::getAchievementDisplay($user, 'dynamic_text')['name'])
            ->toBe('Well done, Test Achiever');

        $user->update(['name' => 'Renamed']);

        expect(AchievementTracker::getAchievementDisplay($user, 'dynamic_text')['name'])
            ->toBe('Well done, Test Achiever');
    });

    it('returns an empty payload for an unknown key', function () {
        expect(AchievementTracker::getAchievementDisplay(createUser(), 'no_such_achievement'))->toBe([]);
    });
});

describe('invalidation', function () {
    beforeEach(fn () => useAchievements('Display'));

    it('drops the text caches when an achievement is awarded', function () {
        $user = createUser();

        DynamicTextAchievement::getCachedName($user);
        DynamicTextAchievement::getCachedDescription($user);

        $user->update(['name' => 'Renamed', 'points' => 5]);
        AchievementTracker::award($user, 'dynamic_text');

        expect(DynamicTextAchievement::getCachedName($user))->toBe('Well done, Renamed')
            ->and(DynamicTextAchievement::getCachedDescription($user))->toBe('You are on 5 points');
    });

    it('drops the text caches when progress is incremented', function () {
        $user = createUser();

        DynamicTextAchievement::getCachedName($user);

        $user->update(['name' => 'Renamed']);
        AchievementTracker::increment($user, 'dynamic_text');

        expect(DynamicTextAchievement::getCachedName($user))->toBe('Well done, Renamed');
    });

    it('drops the text caches when a check moves progress', function () {
        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);

        DynamicTextAchievement::getCachedName($user);

        $user->update(['name' => 'Renamed', 'points' => 2]);
        AchievementTracker::processCheck($user);

        expect(DynamicTextAchievement::getCachedName($user))->toBe('Well done, Renamed');
    });

    it('drops the display payload when an achievement is awarded', function () {
        $user = createUser();

        AchievementTracker::getAchievementDisplay($user, 'dynamic_text');
        AchievementTracker::award($user, 'dynamic_text');

        expect(AchievementTracker::getAchievementDisplay($user, 'dynamic_text')['is_unlocked'])->toBeTrue();
    });

    it('clears every cache for an achiever on request', function () {
        $user = createUser();

        DynamicTextAchievement::getCachedName($user);
        DynamicTextAchievement::getCachedDescription($user);
        AchievementTracker::getAchievementDisplay($user, 'dynamic_text');
        DynamicTextAchievement::getAchievementRecord($user);

        $user->clearAchievementCaches();

        expect(Cache::get(nameKey($user, 'dynamic_text')))->toBeNull()
            ->and(Cache::get(descriptionKey($user, 'dynamic_text')))->toBeNull()
            ->and(Cache::get(displayKey($user, 'dynamic_text')))->toBeNull()
            ->and(Cache::get(recordKey($user, 'dynamic_text')))->toBeNull();
    });

    it('leaves other achievers alone when clearing one achiever', function () {
        $user = createUser(name: 'First');
        $other = createUser(name: 'Second');

        DynamicTextAchievement::getCachedName($user);
        DynamicTextAchievement::getCachedName($other);

        AchievementTracker::clearUserCache($user);

        expect(Cache::get(nameKey($user, 'dynamic_text')))->toBeNull()
            ->and(Cache::get(nameKey($other, 'dynamic_text')))->toBe('Well done, Second');
    });
});

describe('the achievement record cache', function () {
    beforeEach(fn () => useAchievements('Progress'));

    it('reflects progress written by a later check', function () {
        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);

        expect(CountingAchievement::getProgress($user))->toBe(1);

        $user->update(['points' => 2]);
        AchievementTracker::processCheck($user);

        expect(CountingAchievement::getProgress($user))->toBe(2);
    });

    it('reflects an unlock written by award()', function () {
        $user = createUser();

        AchievementTracker::increment($user, 'counting');

        expect(CountingAchievement::isUnlocked($user))->toBeFalse();

        AchievementTracker::award($user, 'counting');

        expect(CountingAchievement::isUnlocked($user))->toBeTrue();
    });

    it('reflects progress written by increment()', function () {
        $user = createUser();

        AchievementTracker::increment($user, 'counting');

        expect(CountingAchievement::getProgress($user))->toBe(1);

        AchievementTracker::increment($user, 'counting');

        expect(CountingAchievement::getProgress($user))->toBe(2);
    });
});

describe('discovery cache', function () {
    it('reads the achievement list back out of the cache', function () {
        useAchievements('Chain');

        expect(AchievementTracker::getAchievementKeys())->toHaveCount(3);

        // Statics cleared, cache left alone: the next call must come from cache.
        AchievementTracker::resetDiscovery();
        Cache::put(discoveryKey(), ['planted' => CountingAchievement::class], 600);

        expect(AchievementTracker::getAchievementKeys())->toBe(['planted']);
    });

    it('rediscovers rather than fatals when a cached class has been deleted', function () {
        useAchievements('Progress');
        AchievementTracker::getAchievementKeys();

        // As left behind by a deploy that removed a definition: the cached
        // list still names it, and the class no longer exists.
        AchievementTracker::resetDiscovery();
        Cache::put(discoveryKey(), [
            'counting' => CountingAchievement::class,
            'deleted' => 'App\\Definitions\\Achievements\\DeletedAchievement',
        ], 600);

        AchievementTracker::processCheck(createUser(points: 1));

        expect(AchievementTracker::getAchievementKeys())->toEqualCanonicalizing(['counting', 'dormant'])
            ->and(Cache::get(discoveryKey()))->not->toHaveKey('deleted');
    });

    it('rediscovers from disk once the cache is cleared', function () {
        useAchievements('Chain');
        AchievementTracker::getAchievementKeys();

        AchievementTracker::clearDiscoveryCache();

        expect(Cache::get(discoveryKey()))->toBeNull()
            ->and(AchievementTracker::getAchievementKeys())->toHaveCount(3);
    });
});

function discoveryKey(): string
{
    return 'merit:achievements:discovery:'.md5(
        config('merit.achievements_path').config('merit.achievements_namespace')
    );
}
