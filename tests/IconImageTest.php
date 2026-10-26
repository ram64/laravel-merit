<?php

use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\IconicAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\MysteryAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PersonalAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PlainImageAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\RemoteImageAchievement;

beforeEach(fn () => useAchievements('Images'));

describe('the definition methods', function () {
    it('default to null', function () {
        expect(PlainImageAchievement::icon())->toBeNull()
            ->and(PlainImageAchievement::image())->toBeNull()
            ->and(PlainImageAchievement::hiddenIcon())->toBeNull()
            ->and(PlainImageAchievement::icon(createUser()))->toBeNull()
            ->and(PlainImageAchievement::image(createUser()))->toBeNull();
    });

    it('return what a definition overrides them with', function () {
        expect(IconicAchievement::icon())->toBe('tabler-crown')
            ->and(IconicAchievement::image())->toBe('badges/iconic.png')
            ->and(RemoteImageAchievement::image())->toBe('https://cdn.example.com/badges/remote.webp?size=512')
            ->and(MysteryAchievement::hiddenIcon())->toBe('tabler-question-mark');
    });

    it('hand the achiever to an override that depends on it', function () {
        expect(PersonalAchievement::icon(createUser(points: 1)))->toBe('tabler-star')
            ->and(PersonalAchievement::icon(createUser(points: 5)))->toBe('tabler-star-filled');
    });
});
