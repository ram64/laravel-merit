<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Batch\BatchOneAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Batch\BatchSecretAchievement;

beforeEach(fn () => useAchievements('Batch'));

const BATCH_KEYS = ['batch_one', 'batch_two', 'batch_three'];

it('returns a name and a description for every requested key', function () {
    $user = createUser();

    $display = AchievementTracker::getDisplayTextMany($user, BATCH_KEYS);

    expect(array_keys($display))->toEqualCanonicalizing(BATCH_KEYS)
        ->and($display['batch_two'])->toBe([
            'name' => 'batch_two name',
            'description' => 'batch_two description',
            'icon' => null,
            'image_url' => null,
        ]);
});

it('matches the singular methods exactly, cold', function () {
    $user = createUser(points: 4, name: 'Ada');

    foreach (BATCH_KEYS as $key) {
        $definition = AchievementTracker::getAchievement($key);
        $batch = AchievementTracker::getDisplayTextMany($user, [$key]);

        expect($batch[$key]['name'])->toBe($definition::getDisplayName($user))
            ->and($batch[$key]['description'])->toBe($definition::getDisplayDescription($user));
    }
});

it('matches the singular methods exactly, warm', function () {
    $user = createUser(points: 4, name: 'Ada');

    // Warm both sides, then compare what each serves from cache.
    AchievementTracker::getDisplayTextMany($user, BATCH_KEYS);

    expect(AchievementTracker::getDisplayTextMany($user, ['batch_one'])['batch_one'])
        ->toBe([
            'name' => BatchOneAchievement::getDisplayName($user),
            'description' => BatchOneAchievement::getDisplayDescription($user),
            'icon' => BatchOneAchievement::getDisplayIcon($user),
            'image_url' => BatchOneAchievement::getDisplayImage($user),
        ])
        ->and(AchievementTracker::getDisplayTextMany($user, ['batch_one'])['batch_one']['name'])
        ->toBe('One for Ada');
});

it('omits unknown keys rather than returning them as nulls', function () {
    $user = createUser();

    $display = AchievementTracker::getDisplayTextMany($user, ['batch_one', 'no_such_achievement']);

    expect($display)->toHaveKey('batch_one')
        ->and($display)->not->toHaveKey('no_such_achievement')
        ->and($display)->toHaveCount(1);
});

it('returns an empty array when nothing is known', function () {
    expect(AchievementTracker::getDisplayTextMany(createUser(), ['nope', 'also_nope']))->toBe([])
        ->and(AchievementTracker::getDisplayTextMany(createUser(), []))->toBe([]);
});

it('asks for each key once however many times it appears', function () {
    $user = createUser();

    $display = AchievementTracker::getDisplayTextMany($user, ['batch_one', 'batch_one', 'batch_one']);

    expect($display)->toHaveCount(1)
        ->and($display['batch_one']['name'])->toBe('One for Test Achiever');
});

describe('the secret gate', function () {
    it('hides a locked secret on the cold path', function () {
        $user = createUser();

        expect(AchievementTracker::getDisplayTextMany($user, ['batch_secret'])['batch_secret'])
            ->toBe([
                'name' => 'Hidden Achievement',
                'description' => 'Complete certain actions to unlock this secret achievement.',
                'icon' => null,
                'image_url' => null,
            ]);
    });

    it('hides a locked secret whose real text is already cached', function () {
        // The trap: the gate sits in front of the cache, so the cache holds the
        // real name even while the achievement is locked. A batch read that
        // went straight to the cache would serve it.
        $user = createUser();

        BatchSecretAchievement::getCachedName($user);
        BatchSecretAchievement::getCachedDescription($user);

        expect(Cache::get('merit:name:'.$user::class.':'.$user->getKey().':batch_secret'))
            ->toBe('The Quiet One');

        expect(AchievementTracker::getDisplayTextMany($user, ['batch_secret'])['batch_secret']['name'])
            ->toBe('Hidden Achievement');
    });

    it('reveals a secret once it is unlocked', function () {
        $user = createUser();

        AchievementTracker::award($user, 'batch_secret');

        expect(AchievementTracker::getDisplayTextMany($user, ['batch_secret'])['batch_secret'])
            ->toBe([
                'name' => 'The Quiet One',
                'description' => 'You found the thing nobody mentions.',
                'icon' => null,
                'image_url' => null,
            ]);
    });

    it('leaves the achievements either side of a secret alone', function () {
        $user = createUser();

        $display = AchievementTracker::getDisplayTextMany($user, ['batch_one', 'batch_secret', 'batch_two']);

        expect($display['batch_one']['name'])->toBe('One for Test Achiever')
            ->and($display['batch_secret']['name'])->toBe('Hidden Achievement')
            ->and($display['batch_two']['name'])->toBe('batch_two name');
    });
});

describe('round trips', function () {
    beforeEach(function () {
        // The database driver is where this actually hurts, and it is the one
        // that makes round trips countable.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        config()->set('cache.default', 'database');
    });

    it('reads every hit in a single round trip', function () {
        $user = createUser();

        // Warm the text caches, and the discovery cache with them, so what is
        // counted below is only the display read.
        AchievementTracker::getDisplayTextMany($user, BATCH_KEYS);

        $queries = countQueries(fn () => AchievementTracker::getDisplayTextMany($user, BATCH_KEYS));

        expect($queries)->toBe(1);
    });

    it('costs two round trips per achievement through the singular methods', function () {
        $user = createUser();

        AchievementTracker::getDisplayTextMany($user, BATCH_KEYS);

        $queries = countQueries(function () use ($user) {
            foreach (BATCH_KEYS as $key) {
                $definition = AchievementTracker::getAchievement($key);
                $definition::getDisplayName($user);
                $definition::getDisplayDescription($user);
            }
        });

        // This is the number the batch method exists to collapse.
        expect($queries)->toBe(2 * count(BATCH_KEYS));
    });

    it('does not spend a lookup per secret achievement', function () {
        // The regression: the secret gate asked isUnlocked() per secret, which
        // on this driver is a query each, and a page carrying a few of them
        // came out worse than the hand-rolled batch this method replaced.
        $user = createUser();

        $keys = [...BATCH_KEYS, 'batch_secret'];
        AchievementTracker::getDisplayTextMany($user, $keys);

        $queries = countQueries(fn () => AchievementTracker::getDisplayTextMany($user, $keys));

        // One for the text, one for the unlocked set behind the gate.
        expect($queries)->toBe(2);
    });

    it('costs nothing extra when the batch holds no secrets', function () {
        $user = createUser();

        AchievementTracker::getDisplayTextMany($user, BATCH_KEYS);

        expect(countQueries(fn () => AchievementTracker::getDisplayTextMany($user, BATCH_KEYS)))->toBe(1);
    });

    it('holds at one lookup however many secrets are in the batch', function () {
        useAchievements('Secret');

        $user = createUser();

        $keys = ['hidden_gem', 'open_book'];
        AchievementTracker::getDisplayTextMany($user, $keys);

        expect(countQueries(fn () => AchievementTracker::getDisplayTextMany($user, $keys)))->toBe(2);
    });

    it('warms the cache on the cold path so the next call is all hits', function () {
        $user = createUser();

        // One call to settle discovery, on a key the measured call does not use.
        AchievementTracker::getDisplayTextMany($user, ['batch_one']);

        $cold = countQueries(fn () => AchievementTracker::getDisplayTextMany($user, ['batch_two', 'batch_three']));
        $warm = countQueries(fn () => AchievementTracker::getDisplayTextMany($user, ['batch_two', 'batch_three']));

        expect($warm)->toBe(1)
            ->and($cold)->toBeGreaterThan($warm);
    });
});

function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $callback();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}
