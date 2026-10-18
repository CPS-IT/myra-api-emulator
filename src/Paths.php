<?php

declare(strict_types=1);

namespace CPSIT\MyraApiEmulator;

/**
 * Where the package keeps its parts, for consumers that run or read them (tests, images).
 */
final class Paths
{
    /** The captured answers: `<version>/<area>/<case>.json`. */
    public static function fixtures(): string
    {
        return dirname(__DIR__) . '/fixtures';
    }

    /** The router script for `php -S`. */
    public static function router(): string
    {
        return dirname(__DIR__) . '/public/index.php';
    }
}
