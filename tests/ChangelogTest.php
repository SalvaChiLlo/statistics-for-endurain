<?php

declare(strict_types=1);

namespace App\Tests;

use App\Application\AppVersion;
use PHPUnit\Framework\TestCase;

class ChangelogTest extends TestCase
{
    public function testThatChangelogContainsLatestVersion(): void
    {
        $changelog = file_get_contents(__DIR__.'/../CHANGELOG.md');

        // This fork does its own independent releases (CHANGELOG.md + git
        // tags in this repo), so entries link back to this fork's own
        // compare view, not upstream Dreeve.
        $latestVersion = AppVersion::getSemanticVersion();
        $this->assertStringContainsString(
            sprintf('# [%s](https://github.com/SalvaChiLlo/statistics-for-endurain/compare/', $latestVersion),
            $changelog
        );
    }
}
