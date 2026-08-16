<?php

use Ram64\LaravelMerit\Services\AchievementTracker;

it('discovers the definitions in the configured directory', function () {
    useAchievements('Chain');

    expect(AchievementTracker::getAchievementKeys())
        ->toEqualCanonicalizing(['chain_one', 'chain_two', 'chain_three']);
});

it('registers nothing when the achievements path does not exist', function () {
    config()->set('merit.achievements_path', __DIR__.'/Fixtures/Achievements/DoesNotExist');
    AchievementTracker::clearDiscoveryCache();

    expect(AchievementTracker::getAllAchievements())->toBe([]);
});

it('skips the abstract base definition', function () {
    // The whole fixture tree, so the abstract FixtureAchievement is globbed too.
    config()->set('merit.achievements_namespace', 'Ram64\\LaravelMerit\\Tests\\Fixtures\\Achievements\\');
    config()->set('merit.achievements_path', __DIR__.'/Fixtures/Achievements');
    AchievementTracker::clearDiscoveryCache();

    expect(AchievementTracker::getAchievementKeys())->not->toContain('fixture_achievement')
        ->and(AchievementTracker::getAchievementKeys())->toContain('chain_one');
});

it('orders a prerequisite ahead of its dependents in a chain', function () {
    useAchievements('Chain');

    $order = achievementKeys(AchievementTracker::getAllAchievements());

    expect($order)->toBe(['chain_one', 'chain_two', 'chain_three']);
});

it('sorts the chain into an order discovery did not hand it', function () {
    useAchievements('Chain');

    // Discovery is alphabetical by filename: StepOne, StepThree, StepTwo. If the
    // sort were a no-op this test would fail, which is the point of it.
    expect(achievementKeys(AchievementTracker::getAllAchievements()))
        ->not->toBe(['chain_one', 'chain_three', 'chain_two']);
});

it('orders both branches of a diamond ahead of the join', function () {
    useAchievements('Diamond');

    $at = achievementPositions(AchievementTracker::getAllAchievements());

    expect($at['diamond_root'])->toBeLessThan($at['diamond_left'])
        ->and($at['diamond_root'])->toBeLessThan($at['diamond_right'])
        ->and($at['diamond_left'])->toBeLessThan($at['diamond_join'])
        ->and($at['diamond_right'])->toBeLessThan($at['diamond_join']);
});

it('resolves prerequisites given as a class name as well as a key', function () {
    // chain_two names its prerequisite by key, chain_three by class. Both edges
    // have to exist for the chain to come back fully ordered.
    useAchievements('Chain');

    expect(achievementKeys(AchievementTracker::getAllAchievements()))
        ->toBe(['chain_one', 'chain_two', 'chain_three']);
});

it('ignores an edge to a prerequisite that is not discovered', function () {
    // diamond_left names 'diamond_root', which is absent from the Chain group.
    // The sort must not invent a node for it.
    useAchievements('Chain');

    expect(AchievementTracker::getAllAchievements())->toHaveCount(3);
});

describe('cycle fallback', function () {
    it('returns every achievement in discovery order rather than hanging or throwing', function () {
        useAchievements('Cycle');

        $order = achievementKeys(AchievementTracker::getAllAchievements());

        // Discovery order, not a partial topological order: a partial sort would
        // emit cycle_standalone alone, or first.
        expect($order)->toBe(['cycle_one', 'cycle_standalone', 'cycle_two']);
    });

    it('still processes the achievements outside the cycle', function () {
        useAchievements('Cycle');

        $user = createUser(points: 1);

        AchievementTracker::processCheck($user);

        expect($user->achievements()->pluck('achievement_key')->all())
            ->toBe(['cycle_standalone']);
    });
});
