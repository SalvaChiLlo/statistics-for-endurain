<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\AppVersion;
use PHPUnit\Framework\TestCase;

class AppVersionTest extends TestCase
{
    public function testGetSemanticVersion(): void
    {
        $this->assertMatchesRegularExpression(
            '/^v\d+\.\d+\.\d+$/',
            AppVersion::getSemanticVersion()
        );
    }

    public function testGetSemanticVersionMatchesCurrentRelease(): void
    {
        // This fork's own release line (see CHANGELOG.md). AppVersion is not
        // derived automatically from git tags, so this must be bumped
        // manually alongside the CHANGELOG.md entry for each release.
        $this->assertSame('v5.7.0', AppVersion::getSemanticVersion());
    }
}
