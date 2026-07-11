<?php

declare(strict_types=1);

namespace App\Tests\Domain\Settings;

use App\Domain\Activity\ActivityVisibility;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Settings\ImportSettings;
use PHPUnit\Framework\TestCase;

class ImportSettingsTest extends TestCase
{
    public function testItAppliesDefaultsForAnEmptyConfiguration(): void
    {
        $settings = ImportSettings::fromArray([]);

        // Empty list means "import all".
        $this->assertCount(count(SportType::cases()), $settings->getSportTypesToImport());
        $this->assertCount(count(ActivityVisibility::cases()), $settings->getActivityVisibilitiesToImport());
        $this->assertCount(0, $settings->getActivitiesToSkipDuringImport());
        $this->assertNull($settings->getSkipActivitiesRecordedBefore());
        $this->assertFalse($settings->getOptInToSegmentDetailsImport()->hasOptedIn());
    }

    public function testItBuildsFromStoredValues(): void
    {
        $settings = ImportSettings::fromArray([
            'numberOfNewActivitiesToProcessPerImport' => 10,
            'sportTypesToImport' => ['Ride'],
            'activityVisibilitiesToImport' => ['everyone'],
            'skipActivitiesRecordedBefore' => '2023-09-01',
            'activitiesToSkipDuringImport' => ['123', '456'],
            'optInToSegmentDetailImport' => true,
        ]);

        $this->assertTrue($settings->getSportTypesToImport()->has(SportType::RIDE));
        $this->assertCount(1, $settings->getSportTypesToImport());
        $this->assertTrue($settings->getActivityVisibilitiesToImport()->has(ActivityVisibility::EVERYONE));
        $this->assertCount(1, $settings->getActivityVisibilitiesToImport());
        $this->assertCount(2, $settings->getActivitiesToSkipDuringImport());
        $this->assertSame('2023-09-01', $settings->getSkipActivitiesRecordedBefore()?->format('Y-m-d'));
        $this->assertTrue($settings->getOptInToSegmentDetailsImport()->hasOptedIn());
    }

    public function testItThrowsForAnInvalidSportType(): void
    {
        $this->expectException(\ValueError::class);

        ImportSettings::fromArray(['sportTypesToImport' => ['NotASportType']]);
    }
}
