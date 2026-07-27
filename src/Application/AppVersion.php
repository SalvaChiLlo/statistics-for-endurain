<?php

declare(strict_types=1);

namespace App\Application;

final readonly class AppVersion
{
    // NOTE: This is not derived automatically from git tags or CHANGELOG.md.
    // Bump this constant manually in the same PR that adds a CHANGELOG.md
    // entry for a new release of this fork.
    private const int MAJOR = 5;
    private const int MINOR = 7;
    private const int PATCH = 0;

    public static function getSemanticVersion(): string
    {
        return sprintf('v%d.%d.%d', self::MAJOR, self::MINOR, self::PATCH);
    }
}
