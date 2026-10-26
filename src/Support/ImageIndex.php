<?php

namespace Ram64\LaravelMerit\Support;

use Illuminate\Support\Facades\File;

/**
 * The files in the achievement image folder, read once.
 *
 * The index is built at discovery and cached with the discovered definitions,
 * so resolving an image never touches the filesystem: a page of fifty
 * achievements costs no stat calls at all. The flip side is that a picture
 * added or replaced on disk is not seen until the discovery cache is cleared.
 */
final class ImageIndex
{
    /**
     * Every file under a folder, keyed by its path relative to the folder
     * (always with forward slashes), with its modified time.
     *
     * No path, or a path that is not a directory, is an empty index, and no
     * filesystem call is made for a missing path at all.
     *
     * @return array<string, int>
     */
    public static function scan(?string $path): array
    {
        if ($path === null || $path === '' || ! File::isDirectory($path)) {
            return [];
        }

        // A replaced file's new time has to be read, not PHP's remembered one.
        clearstatcache();

        $index = [];

        foreach (File::allFiles($path) as $file) {
            $index[str_replace('\\', '/', $file->getRelativePathname())] = (int) $file->getMTime();
        }

        ksort($index);

        return $index;
    }
}
