<?php

declare(strict_types=1);

namespace App\Tests\Console\Endurain;

use App\Console\Endurain\EndurainBackfillRouteGeographyConsoleCommand;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Route\RouteGeography;
use App\Infrastructure\ValueObject\Geography\EncodedPolyline;
use App\Tests\Console\ConsoleCommandTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class EndurainBackfillRouteGeographyConsoleCommandTest extends ConsoleCommandTestCase
{
    private EndurainBackfillRouteGeographyConsoleCommand $endurainBackfillRouteGeographyConsoleCommand;
    private ActivityRepository $activityRepository;

    public function testBackfillsRouteGeographyForAlreadyImportedEndurainActivities(): void
    {
        // This mirrors the state of activities imported before the fix for
        // #58: no starting coordinate, no reverse-geocoded routeGeography,
        // but a valid polyline.
        $this->activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('endurain-1'))
                ->withImportSource(ImportSource::ENDURAIN_API)
                ->withPolyline((string) EncodedPolyline::fromCoordinates([[50.8, 4.3], [50.81, 4.31]]))
                ->withRouteGeography(RouteGeography::create([]))
                ->build(),
            rawData: [],
        ));

        // A Strava-imported activity should never be touched by this
        // Endurain-only backfill.
        $this->activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('strava-1'))
                ->withImportSource(ImportSource::STRAVA_API)
                ->withRouteGeography(RouteGeography::create([]))
                ->build(),
            rawData: [],
        ));

        $command = $this->getCommandInApplication(EndurainBackfillRouteGeographyConsoleCommand::NAME);
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        $backfilledActivity = $this->activityRepository->find(ActivityId::fromUnprefixed('endurain-1'));
        $this->assertNotNull($backfilledActivity->getStartingCoordinate());
        $this->assertTrue($backfilledActivity->getRouteGeography()->isReversedGeocoded());
        $this->assertEquals('be', $backfilledActivity->getRouteGeography()->getStartingPointCountryCode());

        $untouchedStravaActivity = $this->activityRepository->find(ActivityId::fromUnprefixed('strava-1'));
        $this->assertFalse($untouchedStravaActivity->getRouteGeography()->isReversedGeocoded());

        $this->assertStringContainsString('Backfilled routeGeography for 1 activities, skipped 0', $commandTester->getDisplay());
    }

    public function testSkipsActivitiesAlreadyFullyGeocodedAndIsSafeToRerun(): void
    {
        $this->activityRepository->add(ActivityWithRawData::fromState(
            activity: ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('endurain-1'))
                ->withImportSource(ImportSource::ENDURAIN_API)
                ->withPolyline((string) EncodedPolyline::fromCoordinates([[50.8, 4.3], [50.81, 4.31]]))
                ->withRouteGeography(RouteGeography::create([]))
                ->build(),
            rawData: [],
        ));

        $command = $this->getCommandInApplication(EndurainBackfillRouteGeographyConsoleCommand::NAME);

        $firstRun = new CommandTester($command);
        $firstRun->execute(['command' => $command->getName()]);
        $this->assertStringContainsString('Backfilled routeGeography for 1 activities, skipped 0', $firstRun->getDisplay());

        $secondRun = new CommandTester($command);
        $secondRun->execute(['command' => $command->getName()]);
        $this->assertStringContainsString('Backfilled routeGeography for 0 activities, skipped 1', $secondRun->getDisplay());
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->endurainBackfillRouteGeographyConsoleCommand = $this->getContainer()->get(EndurainBackfillRouteGeographyConsoleCommand::class);
        $this->activityRepository = $this->getContainer()->get(ActivityRepository::class);
    }

    protected function getConsoleCommand(): Command
    {
        return $this->endurainBackfillRouteGeographyConsoleCommand;
    }
}
