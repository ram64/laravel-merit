<?php

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

it('scopes the prerequisite check to the achiever that owns the row', function () {
    $user = createUser();
    $other = createUser();

    storeProgress($other, 'chain_one');

    expect(StepTwoAchievement::canStart($user))->toBeFalse()
        ->and(StepTwoAchievement::canStart($other))->toBeTrue();
});
