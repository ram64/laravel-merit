<?php

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;

/**
 * Count cache reads and deletes made during the callback.
 *
 * @return array{reads: int, forgets: int}
 */
function cacheOperationsDuring(callable $callback): array
{
    $counts = ['reads' => 0, 'forgets' => 0];

    Event::listen([CacheHit::class, CacheMissed::class], function () use (&$counts) {
        $counts['reads']++;
    });
    Event::listen([KeyForgotten::class, KeyForgetFailed::class], function () use (&$counts) {
        $counts['forgets']++;
    });

    $callback();

    Event::forget(CacheHit::class);
    Event::forget(CacheMissed::class);
    Event::forget(KeyForgotten::class);
    Event::forget(KeyForgetFailed::class);

    return $counts;
}

/**
 * An achiever with one achievement unlocked, one in progress and the rest
 * untouched, the secret one included.
 */
function displayAchiever()
{
    $user = createUser(points: 1, name: 'Ada');
    AchievementTracker::award($user, 'batch_one');

    return $user;
}

const DISPLAY_KEYS = ['batch_three', 'batch_secret', 'batch_one', 'batch_two'];

describe('getAchievementDisplayMany()', function () {
    beforeEach(fn () => useAchievements('Batch'));

    it('gives exactly what getAchievementDisplay() gives for each key', function () {
        $user = displayAchiever();

        $many = AchievementTracker::getAchievementDisplayMany($user, DISPLAY_KEYS);

        Cache::flush();

        foreach (DISPLAY_KEYS as $key) {
            expect($many[$key])->toBe(AchievementTracker::getAchievementDisplay($user, $key));
        }
    });

    it('keeps the order asked for and omits unknown keys', function () {
        $user = displayAchiever();

        $many = AchievementTracker::getAchievementDisplayMany($user, ['batch_two', 'no_such_key', 'batch_one']);

        expect(array_keys($many))->toBe(['batch_two', 'batch_one']);
    });

    it('builds a cold page from one query, however many achievements are on it', function () {
        $user = displayAchiever();
        Cache::flush();
        AchievementTracker::getAchievementKeys();

        DB::flushQueryLog();
        DB::enableQueryLog();
        AchievementTracker::getAchievementDisplayMany($user, DISPLAY_KEYS);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(1);
    });

    it('serves a warm page in one round trip on the database cache driver', function () {
        // The driver that makes round trips countable: every cache read is a
        // query, alongside any the method makes itself.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config()->set('cache.default', 'database');

        $user = displayAchiever();
        AchievementTracker::getAchievementDisplayMany($user, DISPLAY_KEYS);

        DB::flushQueryLog();
        DB::enableQueryLog();
        AchievementTracker::getAchievementDisplayMany($user, DISPLAY_KEYS);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queries)->toBe(1);
    });
});

describe('cost of the singular calls', function () {
    beforeEach(fn () => useAchievements('Progress'));

    it('reads the row once when building a display payload', function () {
        $user = createUser(points: 1);
        AchievementTracker::processCheck($user);
        AchievementTracker::getAchievementKeys();

        // The payload, the row, the name and the description: one read each,
        // where the row alone used to be read five times.
        $operations = cacheOperationsDuring(fn () => AchievementTracker::getAchievementDisplay($user, 'counting'));

        expect($operations['reads'])->toBe(4);
    });

    it('clears only the achievement it moved in unlockOrProgress()', function () {
        $user = createUser();
        AchievementTracker::getAchievementKeys();

        // Four keys for the achievement, the achiever's own, and the legacy one.
        $operations = cacheOperationsDuring(fn () => $user->unlockOrProgress(CountingAchievement::class));

        expect($operations['forgets'])->toBe(6);
    });
});
