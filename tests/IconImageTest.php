<?php

use Illuminate\Support\Facades\File;
use Ram64\LaravelMerit\LaravelMeritServiceProvider;
use Ram64\LaravelMerit\Services\AchievementTracker;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\CategorisedAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\IconicAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\MysteryAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PersonalAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\PlainImageAchievement;
use Ram64\LaravelMerit\Tests\Fixtures\Achievements\Images\QuietAchievement;
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

describe('getDisplayImage()', function () {
    it('finds the conventional file, webp before png', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'plain.png', 1_700_000_001);
        putImage($folder, 'plain.webp', 1_700_000_002);
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage(createUser()))
            ->toBe('/images/achievements/plain.webp?v=1700000002');
    });

    it('falls back to the next extension, then to null', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'plain.png', 1_700_000_001);
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage(createUser()))
            ->toBe('/images/achievements/plain.png?v=1700000001');

        File::delete("{$folder}/plain.png");
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage(createUser()))->toBeNull();
    });

    it('follows the configured extension order', function () {
        $folder = useImageFolder($this);
        config()->set('merit.images.extensions', ['png', 'webp']);
        putImage($folder, 'plain.png');
        putImage($folder, 'plain.webp');
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage())->toStartWith('/images/achievements/plain.png?v=');
    });

    it('resolves a relative image() against the folder, before any convention file', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'badges/iconic.png', 1_700_000_003);
        putImage($folder, 'iconic.webp');
        AchievementTracker::clearDiscoveryCache();

        expect(IconicAchievement::getDisplayImage(createUser()))
            ->toBe('/images/achievements/badges/iconic.png?v=1700000003');
    });

    it('passes a full URL from image() through unchanged', function () {
        useImageFolder($this);

        expect(RemoteImageAchievement::getDisplayImage(createUser()))
            ->toBe('https://cdn.example.com/badges/remote.webp?size=512');
    });

    it('gives a replaced file a new version, once the cache is cleared', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'plain.webp', 1_700_000_000);
        AchievementTracker::clearDiscoveryCache();

        $before = PlainImageAchievement::getDisplayImage();

        touch("{$folder}/plain.webp", 1_700_000_500);
        AchievementTracker::clearDiscoveryCache();

        expect($before)->toBe('/images/achievements/plain.webp?v=1700000000')
            ->and(PlainImageAchievement::getDisplayImage())->toBe('/images/achievements/plain.webp?v=1700000500');
    });

    it('looks nothing up when no path is configured', function () {
        config()->set('merit.images.path', null);
        config()->set('merit.images.url', '/images/achievements');
        AchievementTracker::clearDiscoveryCache();

        File::partialMock()->shouldNotReceive('isDirectory');

        expect(PlainImageAchievement::getDisplayImage(createUser()))->toBeNull()
            ->and(IconicAchievement::getDisplayImage(createUser()))->toBe('/images/achievements/badges/iconic.png')
            ->and(RemoteImageAchievement::getDisplayImage(createUser()))->toBe('https://cdn.example.com/badges/remote.webp?size=512');
    });
});

describe('getDisplayIcon()', function () {
    it("prefers the definition's icon, then the category default, then null", function () {
        config()->set('merit.icons.categories', ['fishing' => 'tabler-fish', 'general' => 'tabler-award']);

        expect(IconicAchievement::getDisplayIcon(createUser()))->toBe('tabler-crown')
            ->and(CategorisedAchievement::getDisplayIcon(createUser()))->toBe('tabler-fish')
            ->and(PlainImageAchievement::getDisplayIcon(createUser()))->toBe('tabler-award');

        config()->set('merit.icons.categories', []);

        expect(CategorisedAchievement::getDisplayIcon(createUser()))->toBeNull();
    });

    it('hands the achiever to icon()', function () {
        expect(PersonalAchievement::getDisplayIcon(createUser(points: 9)))->toBe('tabler-star-filled');
    });
});

describe('secret achievements', function () {
    it('hide the image and icon until the achiever unlocks them, using the hidden ones when set', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'mystery.webp', 1_700_000_010);
        putImage($folder, 'secret.webp', 1_700_000_020);
        config()->set('merit.images.hidden', 'secret.webp');
        AchievementTracker::clearDiscoveryCache();

        $user = createUser();

        expect(MysteryAchievement::getDisplayImage($user))->toBe('/images/achievements/secret.webp?v=1700000020')
            ->and(MysteryAchievement::getDisplayIcon($user))->toBe('tabler-question-mark')
            ->and(MysteryAchievement::getDisplayImage())->toBe('/images/achievements/secret.webp?v=1700000020');

        AchievementTracker::award($user, 'mystery');

        expect(MysteryAchievement::getDisplayImage($user))->toBe('/images/achievements/mystery.webp?v=1700000010')
            ->and(MysteryAchievement::getDisplayIcon($user))->toBe('tabler-key')
            ->and(MysteryAchievement::getDisplayImage(createUser()))->toBe('/images/achievements/secret.webp?v=1700000020');
    });

    it('show nothing in their place by default', function () {
        $folder = useImageFolder($this);
        putImage($folder, 'quiet.webp');
        AchievementTracker::clearDiscoveryCache();

        $user = createUser();

        expect(QuietAchievement::getDisplayImage($user))->toBeNull()
            ->and(QuietAchievement::getDisplayIcon($user))->toBeNull();

        AchievementTracker::award($user, 'quiet');

        expect(QuietAchievement::getDisplayImage($user))->toStartWith('/images/achievements/quiet.webp?v=')
            ->and(QuietAchievement::getDisplayIcon($user))->toBe('tabler-moon');
    });

    it('take a full URL for the hidden image as it is', function () {
        config()->set('merit.images.hidden', 'https://cdn.example.com/mystery.webp');

        expect(MysteryAchievement::getDisplayImage(createUser()))->toBe('https://cdn.example.com/mystery.webp');
    });
});

describe('an older published config', function () {
    it('gets the image and icon defaults merged in', function () {
        // As if the application had published config/merit.php from 0.2.
        config()->set('merit', require __DIR__.'/Fixtures/Config/merit-0.2.php');
        app()->register(LaravelMeritServiceProvider::class, force: true);
        AchievementTracker::clearDiscoveryCache();

        expect(config('merit.images'))->toBe(['path' => null, 'url' => null, 'extensions' => ['webp', 'png'], 'hidden' => null])
            ->and(config('merit.icons'))->toBe(['categories' => []])
            ->and(PlainImageAchievement::getDisplayImage(createUser()))->toBeNull()
            ->and(IconicAchievement::getDisplayIcon(createUser()))->toBe('tabler-crown');
    });

    it('works with the keys missing altogether, or only partly set', function () {
        config()->set('merit.images', null);
        config()->set('merit.icons', null);
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage(createUser()))->toBeNull()
            ->and(CategorisedAchievement::getDisplayIcon(createUser()))->toBeNull()
            ->and(MysteryAchievement::getDisplayImage(createUser()))->toBeNull();

        // A partial images array: mergeConfigFrom does not fill in below the
        // top level, so the extensions default has to come from the code.
        $folder = useImageFolder($this);
        putImage($folder, 'plain.png');
        config()->set('merit.images', ['path' => $folder, 'url' => '/img']);
        AchievementTracker::clearDiscoveryCache();

        expect(PlainImageAchievement::getDisplayImage())->toStartWith('/img/plain.png?v=');
    });
});
