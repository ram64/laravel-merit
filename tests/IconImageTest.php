<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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

/**
 * An image folder with a picture for plain, iconic and the hidden secret.
 */
function useDisplayImages($test): void
{
    $folder = useImageFolder($test);
    putImage($folder, 'plain.webp', 1_700_000_001);
    putImage($folder, 'badges/iconic.png', 1_700_000_002);
    putImage($folder, 'mystery.webp', 1_700_000_003);
    putImage($folder, 'secret.webp', 1_700_000_004);
    config()->set('merit.images.hidden', 'secret.webp');
    config()->set('merit.icons.categories', ['fishing' => 'tabler-fish']);
    AchievementTracker::clearDiscoveryCache();
}

const DISPLAY_IMAGE_KEYS = ['plain', 'iconic', 'remote', 'mystery', 'categorised'];

const EXPECTED_ICONS_AND_IMAGES = [
    'plain' => ['icon' => null, 'image_url' => '/images/achievements/plain.webp?v=1700000001'],
    'iconic' => ['icon' => 'tabler-crown', 'image_url' => '/images/achievements/badges/iconic.png?v=1700000002'],
    'remote' => ['icon' => null, 'image_url' => 'https://cdn.example.com/badges/remote.webp?size=512'],
    'mystery' => ['icon' => 'tabler-question-mark', 'image_url' => '/images/achievements/secret.webp?v=1700000004'],
    'categorised' => ['icon' => 'tabler-fish', 'image_url' => null],
];

describe('display data', function () {
    it('carries icon and image_url in getAchievementDisplay()', function () {
        useDisplayImages($this);
        $user = createUser();

        foreach (EXPECTED_ICONS_AND_IMAGES as $key => $expected) {
            expect(AchievementTracker::getAchievementDisplay($user, $key))->toMatchArray($expected)
                ->and($user->getCachedAchievementDisplay($key))->toMatchArray($expected);
        }
    });

    it('carries the same in getAchievementDisplayMany() as the singular call', function () {
        useDisplayImages($this);
        $user = createUser();

        $many = AchievementTracker::getAchievementDisplayMany($user, DISPLAY_IMAGE_KEYS);

        Cache::flush();

        foreach (EXPECTED_ICONS_AND_IMAGES as $key => $expected) {
            expect($many[$key])->toMatchArray($expected)
                ->and($many[$key])->toBe(AchievementTracker::getAchievementDisplay($user, $key));
        }
    });

    it('carries icon and image_url in getDisplayTextMany(), cold and warm', function () {
        useDisplayImages($this);
        $user = createUser();

        $cold = AchievementTracker::getDisplayTextMany($user, DISPLAY_IMAGE_KEYS);
        $warm = AchievementTracker::getDisplayTextMany($user, DISPLAY_IMAGE_KEYS);

        foreach (EXPECTED_ICONS_AND_IMAGES as $key => $expected) {
            expect($cold[$key])->toMatchArray($expected)
                ->and($warm[$key])->toBe($cold[$key]);
        }
    });

    it('reveals the secret in every payload once it is unlocked', function () {
        useDisplayImages($this);
        $user = createUser();
        AchievementTracker::award($user, 'mystery');

        $revealed = ['icon' => 'tabler-key', 'image_url' => '/images/achievements/mystery.webp?v=1700000003'];

        expect(AchievementTracker::getAchievementDisplay($user, 'mystery'))->toMatchArray($revealed)
            ->and(AchievementTracker::getAchievementDisplayMany($user, ['mystery'])['mystery'])->toMatchArray($revealed)
            ->and(AchievementTracker::getDisplayTextMany($user, ['mystery'])['mystery'])->toMatchArray($revealed);
    });

    it('reads a warm page in one cache round trip with both keys present', function () {
        // The driver that makes round trips countable: a cache read is a query.
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        config()->set('cache.default', 'database');

        useDisplayImages($this);
        $user = createUser();

        AchievementTracker::getDisplayTextMany($user, DISPLAY_IMAGE_KEYS);
        AchievementTracker::getAchievementDisplayMany($user, DISPLAY_IMAGE_KEYS);

        foreach (['getDisplayTextMany', 'getAchievementDisplayMany'] as $method) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $display = AchievementTracker::$method($user, DISPLAY_IMAGE_KEYS);
            $log = array_column(DB::getQueryLog(), 'query');
            DB::disableQueryLog();

            $cacheReads = array_filter($log, fn (string $query) => str_contains($query, 'from "cache"'));
            $others = array_values(array_diff($log, $cacheReads));

            // One cache round trip for the page. The text read also makes the
            // secret gate's one read of the unlocked keys, as in 0.2, because
            // a secret is on the page; icons and images add nothing.
            expect($cacheReads)->toHaveCount(1, $method)
                ->and($others)->toBe($method === 'getDisplayTextMany'
                    ? ['select "achievement_key" from "achiever_achievements" where "achiever_id" = ? and "achiever_type" = ? and "unlocked_at" is not null']
                    : []);

            foreach (EXPECTED_ICONS_AND_IMAGES as $key => $expected) {
                expect($display[$key])->toMatchArray($expected);
            }
        }
    });
});

describe('a payload cached by 0.2', function () {
    /**
     * A display payload as 0.2 cached it: everything but icon and image_url.
     */
    function cacheOldDisplay($user, string $key): string
    {
        $cacheKey = 'merit:display:'.$user::class.':'.$user->id.':'.$key;

        Cache::put($cacheKey, [
            'key' => $key,
            'name' => "{$key} name",
            'description' => "{$key} description",
            'goal' => 1,
            'progress' => 0,
            'progress_percentage' => 0.0,
            'is_completed' => false,
            'is_unlocked' => false,
            'is_started' => false,
            'is_secret' => false,
            'is_visible' => true,
            'category' => 'general',
        ], 600);

        return $cacheKey;
    }

    it('is built again by getAchievementDisplay(), and stored with the new keys', function () {
        useDisplayImages($this);
        $user = createUser();
        $cacheKey = cacheOldDisplay($user, 'iconic');

        expect(AchievementTracker::getAchievementDisplay($user, 'iconic'))->toMatchArray(EXPECTED_ICONS_AND_IMAGES['iconic'])
            ->and(Cache::get($cacheKey))->toMatchArray(EXPECTED_ICONS_AND_IMAGES['iconic']);
    });

    it('is built again by getAchievementDisplayMany(), beside entries that are current', function () {
        useDisplayImages($this);
        $user = createUser();
        AchievementTracker::getAchievementDisplay($user, 'plain');
        cacheOldDisplay($user, 'iconic');

        $many = AchievementTracker::getAchievementDisplayMany($user, ['plain', 'iconic']);

        expect($many['plain'])->toMatchArray(EXPECTED_ICONS_AND_IMAGES['plain'])
            ->and($many['iconic'])->toMatchArray(EXPECTED_ICONS_AND_IMAGES['iconic']);
    });
});
