<?php

use Illuminate\Support\Facades\Cache;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Secret\HiddenGemAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Secret\OpenBookAchievement;

beforeEach(function () {
    useAchievements('Secret');
});

it('hides the name and description of a locked secret achievement', function () {
    $user = createUser();

    expect(HiddenGemAchievement::getDisplayName($user))->toBe('Hidden Achievement')
        ->and(HiddenGemAchievement::getDisplayDescription($user))
        ->toBe('Complete certain actions to unlock this secret achievement.');
});

it('reveals the real text once the secret achievement is unlocked', function () {
    $user = createUser();

    AchievementTracker::award($user, 'hidden_gem');

    expect(HiddenGemAchievement::getDisplayName($user))->toBe('Hidden Gem')
        ->and(HiddenGemAchievement::getDisplayDescription($user))
        ->toBe('Found the thing nobody told you about.');
});

it('never hides a non-secret achievement', function () {
    $user = createUser();

    expect(OpenBookAchievement::getDisplayName($user))->toBe('Open Book')
        ->and(OpenBookAchievement::getDisplayDescription($user))->toBe('Nothing hidden about this one.')
        ->and(OpenBookAchievement::isVisibleTo($user))->toBeTrue();
});

it('treats a locked secret achievement as invisible', function () {
    $user = createUser();

    expect(HiddenGemAchievement::isVisibleTo($user))->toBeFalse();

    AchievementTracker::award($user, 'hidden_gem');

    expect(HiddenGemAchievement::isVisibleTo($user))->toBeTrue();
});

it('leaves locked secrets out of the visible list without dropping public ones', function () {
    $user = createUser();

    expect(achievementKeys(AchievementTracker::getVisibleAchievements($user)))->toBe(['open_book']);

    AchievementTracker::award($user, 'hidden_gem');

    expect(achievementKeys(AchievementTracker::getVisibleAchievements($user)))
        ->toEqualCanonicalizing(['open_book', 'hidden_gem']);
});

it('still lists a locked secret achievement in the full set', function () {
    // Secret achievements hide their text, not their existence.
    expect(AchievementTracker::getAchievementKeys())
        ->toEqualCanonicalizing(['hidden_gem', 'open_book']);
});

it('reports the placeholder text through the display payload', function () {
    $user = createUser();

    $display = AchievementTracker::getAchievementDisplay($user, 'hidden_gem');

    expect($display['name'])->toBe('Hidden Achievement')
        ->and($display['description'])->toBe('Complete certain actions to unlock this secret achievement.')
        ->and($display['is_secret'])->toBeTrue()
        ->and($display['is_visible'])->toBeFalse()
        ->and($display['is_unlocked'])->toBeFalse();
});

it('caches the real name of a locked secret achievement, never the placeholder', function () {
    // The secret gate sits in front of the cache, so the cache holds the real
    // text even while the achievement is locked. Anything reading these keys
    // directly — the batch-display workaround in the reference consumer, for
    // one — has to re-apply the gate itself or it leaks the name.
    $user = createUser();

    expect(HiddenGemAchievement::getDisplayName($user))->toBe('Hidden Achievement');

    // Something else warms the cache; getDisplayName above did not.
    HiddenGemAchievement::getCachedName($user);

    expect(Cache::get('merit:name:'.$user::class.':'.$user->id.':hidden_gem'))->toBe('Hidden Gem');
});
