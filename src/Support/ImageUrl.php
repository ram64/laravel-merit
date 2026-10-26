<?php

namespace Ram64\LaravelMerit\Support;

use Ram64\LaravelMerit\Services\AchievementTracker;

/**
 * Turns an achievement picture into the URL to show.
 *
 * A full URL is passed through as it is. A path relative to merit.images.path
 * is joined to merit.images.url and given ?v= with its file's modified time,
 * so a replaced picture gets a new URL and no cache keeps the old one. Which
 * files exist, and their times, come from the index read at discovery: no
 * lookup here touches the filesystem.
 */
final class ImageUrl
{
    /**
     * The URL for an image() value or a configured picture, or null for none.
     */
    public static function resolve(?string $image): ?string
    {
        if ($image === null || trim($image) === '') {
            return null;
        }

        if (self::isUrl($image)) {
            return $image;
        }

        $relative = (string) preg_replace('#^(\./)+#', '', str_replace('\\', '/', $image));

        return self::url($relative, AchievementTracker::getImageIndex()[$relative] ?? null);
    }

    /**
     * The picture found by the naming convention: the first {key}.{ext} in
     * the folder, in merit.images.extensions order, or null.
     *
     * With no folder configured, nothing is looked up.
     */
    public static function forKey(string $key): ?string
    {
        if (! self::hasFolder()) {
            return null;
        }

        $index = AchievementTracker::getImageIndex();

        foreach (self::extensions() as $extension) {
            $relative = "{$key}.{$extension}";

            if (isset($index[$relative])) {
                return self::url($relative, $index[$relative]);
            }
        }

        return null;
    }

    /**
     * A full URL: one with a scheme (https:, data:), protocol-relative (//),
     * or rooted at the site (/). Anything else is relative to the folder.
     */
    private static function isUrl(string $image): bool
    {
        return preg_match('#^([a-z][a-z0-9+.\-]*:|/)#i', $image) === 1;
    }

    private static function url(string $relative, ?int $modified): string
    {
        $path = implode('/', array_map('rawurlencode', explode('/', $relative)));
        $base = config('merit.images.url');

        // Without a configured URL the path is given back as it is, for the
        // application to place.
        $url = is_string($base) && $base !== '' ? rtrim($base, '/').'/'.$path : $path;

        if ($modified === null) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'v='.$modified;
    }

    private static function hasFolder(): bool
    {
        $path = config('merit.images.path');

        return is_string($path) && $path !== '';
    }

    /**
     * A published config may predate the key, or hold a partial images array,
     * which mergeConfigFrom does not fill in below the top level.
     *
     * @return array<int, string>
     */
    private static function extensions(): array
    {
        $extensions = config('merit.images.extensions');

        if (! is_array($extensions) || $extensions === []) {
            return ['webp', 'png'];
        }

        return array_values(array_map(fn ($extension) => ltrim(strtolower((string) $extension), '.'), $extensions));
    }
}
