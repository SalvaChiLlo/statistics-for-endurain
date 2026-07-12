<?php

declare(strict_types=1);

namespace App\Console\Endurain;

use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Route\RouteGeographyReverseGeocoder;
use App\Infrastructure\Console\ProvideConsoleIntro;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Doctrine\Migrations\RequiresUpToDateDatabaseSchema;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressIndicator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off/maintenance backfill for #58: activities imported from Endurain
 * before the reverse-geocoding fix landed never got a starting coordinate or
 * a reverse-geocoded routeGeography, so they were silently filtered out of
 * the Heatmap page (which requires a non-null "$.country_code" in
 * routeGeography).
 *
 * Re-running the regular Endurain sync (app:cron:run-endurain-import) does
 * NOT fix already-imported activities on its own: it only ever imports
 * activities that are new on the remote side (see
 * DetectEndurainActivityChanges::diff()), so previously-imported activities
 * are never re-dispatched through ImportEndurainActivityCommandHandler. This
 * command instead walks the already-imported Endurain activities directly
 * and reverse-geocodes each one in place, without re-fetching anything from
 * the Endurain API.
 *
 * Safe to re-run: activities that are already fully geocoded (both a
 * reverse-geocoded starting point and an analyzed route) are skipped.
 */
#[WithMutex(lockName: LockName::IMPORT_DATA_OR_BUILD_APP)]
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: EndurainBackfillRouteGeographyConsoleCommand::NAME, description: 'Backfill routeGeography for already-imported Endurain activities')]
final class EndurainBackfillRouteGeographyConsoleCommand extends Command
{
    use ProvideConsoleIntro;

    public const string NAME = 'app:endurain:backfill-route-geography';

    public function __construct(
        private readonly ActivityIdRepository $activityIdRepository,
        private readonly ActivityRepository $activityRepository,
        private readonly RouteGeographyReverseGeocoder $routeGeographyReverseGeocoder,
        private readonly Mutex $mutex,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, $output);
        $this->mutex->acquireLock('EndurainBackfillRouteGeographyConsoleCommand');

        $this->outputConsoleIntro($output);

        $activityIds = $this->activityIdRepository->findAllImportedFromEndurainApi();

        $progressIndicator = new ProgressIndicator(
            output: $output,
            indicatorChangeInterval: 100,
            indicatorValues: ['⠏', '⠛', '⠹', '⢸', '⣰', '⣤', '⣆', '⡇']
        );
        $progressIndicator->start(sprintf('Backfilling routeGeography for %d Endurain activities...', count($activityIds)));

        $countBackfilled = 0;
        $countSkipped = 0;
        foreach ($activityIds as $activityId) {
            $this->mutex->heartbeat();
            $progressIndicator->advance();

            $activityWithRawData = $this->activityRepository->findWithRawData($activityId);
            $activity = $activityWithRawData->getActivity();

            $routeGeography = $activity->getRouteGeography();
            if ($routeGeography->isReversedGeocoded() && $routeGeography->hasBeenAnalyzedForRouteGeography()) {
                // Already fully backfilled (or geocoded some other way), nothing to do.
                ++$countSkipped;
                continue;
            }

            if (!$activity->getStartingCoordinate() && $activity->getEncodedPolyline()) {
                // Endurain activities imported before the fix for #58 never had a
                // starting coordinate set (see Activity::createFromRawEndurainData());
                // derive one from the stored polyline so reverse geocoding below has
                // something to work with.
                $activity = $activity->withStartingCoordinate($activity->getEncodedPolyline()->getStartingCoordinate());
            }

            $activity = $this->routeGeographyReverseGeocoder->analyze($activity);

            $this->activityRepository->update(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: $activityWithRawData->getRawData(),
            ));
            ++$countBackfilled;
        }

        $progressIndicator->finish(sprintf(
            'Backfilled routeGeography for %d activities, skipped %d already-geocoded activities',
            $countBackfilled,
            $countSkipped,
        ));

        $this->mutex->releaseLock();

        return Command::SUCCESS;
    }
}
