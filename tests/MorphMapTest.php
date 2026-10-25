<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Progress\CountingAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Models\User;

/**
 * An application with a morph map stores the alias, not the class, in every
 * *_type column Eloquent writes. The package's own reads and writes have to
 * use the same value, or rows written one way are invisible the other.
 */
beforeEach(function () {
    Relation::morphMap(['user' => User::class]);
});

afterEach(function () {
    Relation::morphMap([], merge: false);
});

it('stores the morph alias on rows the tracker writes', function () {
    useAchievements('Progress');

    $user = createUser();

    AchievementTracker::award($user, 'counting');

    expect($user->achievements()->count())->toBe(1)
        ->and($user->achievements()->first()->achiever_type)->toBe('user');
});

it('sees an unlock written through the achievements relation', function () {
    useAchievements('Progress');

    $user = createUser();

    $user->unlockOrProgress(CountingAchievement::class, 3, [], 3);

    expect(CountingAchievement::isUnlocked($user))->toBeTrue();
});

it('keeps the experience log reachable from the experiencer row', function () {
    $user = createUser();

    $user->gainExperience(50);

    $experiencer = $user->experiencer()->first();

    expect($experiencer->experiencer_type)->toBe('user')
        ->and($experiencer->experienceLogs()->count())->toBe(1)
        ->and($user->experienceLogs()->count())->toBe(1);
});
