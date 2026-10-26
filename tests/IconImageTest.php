<?php

use Illuminate\Support\Facades\File;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\IconicAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\MysteryAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PersonalAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PlainImageAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\RemoteImageAchievement;

beforeEach(fn () => useAchievements('Images'));

afterEach(function () {
    if (isset($this->imageFolder)) {
        File::deleteDirectory($this->imageFolder);
    }
});

/**
 * A fresh image folder for this test, configured and empty.
 */
function useImageFolder($test, ?string $url = '/images/achievements'): string
{
    $test->imageFolder = sys_get_temp_dir().'/merit-images-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($test->imageFolder);

    config()->set('merit.images.path', $test->imageFolder);
    config()->set('merit.images.url', $url);
    AchievementTracker::clearDiscoveryCache();

    return $test->imageFolder;
}

/**
 * Put a file in the image folder, with a given modified time.
 */
function putImage(string $folder, string $name, int $modified = 1_700_000_000): void
{
    File::ensureDirectoryExists(dirname("{$folder}/{$name}"));
    File::put("{$folder}/{$name}", 'image');
    touch("{$folder}/{$name}", $modified);
}

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

describe('the image index', function () {
    it('lists the folder once, by relative path, with modified times', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'plain.webp', 1_700_000_001);
        putImage($folder, 'badges/iconic.png', 1_700_000_002);

        expect(AchievementTracker::getImageIndex())->toBe([
            'badges/iconic.png' => 1_700_000_002,
            'plain.webp' => 1_700_000_001,
        ]);
    });

    it('looks nothing up when no path is configured', function () {
        config()->set('merit.images.path', null);
        AchievementTracker::clearDiscoveryCache();

        File::partialMock()->shouldNotReceive('isDirectory');

        expect(AchievementTracker::getImageIndex())->toBe([])
            ->and(AchievementTracker::getAchievementKeys())->toContain('plain');
    });

    it('is read from the cache, not the disk, until the discovery cache is cleared', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'plain.webp');
        AchievementTracker::getImageIndex();

        // Another process, so only the cache entry carries over.
        File::delete("{$folder}/plain.webp");
        AchievementTracker::resetDiscovery();

        expect(AchievementTracker::getImageIndex())->toHaveKey('plain.webp');

        AchievementTracker::clearDiscoveryCache();

        expect(AchievementTracker::getImageIndex())->toBe([]);
    });
});
