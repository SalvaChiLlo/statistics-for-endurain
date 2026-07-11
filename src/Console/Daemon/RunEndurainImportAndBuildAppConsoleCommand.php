<?php

declare(strict_types=1);

namespace App\Console\Daemon;

use App\Application\AppIsNotReady;
use App\Application\AppStatusChecker;
use App\Application\Build\RunBuild\RunBuild;
use App\Application\Import\CalculateActivityMetrics\CalculateActivityMetrics;
use App\Application\Import\EndurainImport\DetectEndurainActivityChanges\DetectEndurainActivityChanges;
use App\Application\Import\EndurainImport\ImportEndurainActivity\ImportEndurainActivity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityIdRepository;
use App\Domain\Activity\ActivityIds;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Endurain\Endurain;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\DependencyInjection\Mutex\WithMutex;
use App\Infrastructure\Doctrine\Migrations\RequiresUpToDateDatabaseSchema;
use App\Infrastructure\Logging\LoggableConsoleOutput;
use App\Infrastructure\Mutex\LockIsAlreadyAcquired;
use App\Infrastructure\Mutex\LockName;
use App\Infrastructure\Mutex\Mutex;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Orchestrates a full Endurain sync: fetch the remote activity list, diff it against
 * what's locally imported, import new activities (with streams/gear/polyline), mark
 * activities no longer present remotely for deletion, and rebuild the dashboard.
 *
 * Intentionally smaller in scope than RunStravaImportAndBuildAppConsoleCommand: no
 * rate-limit display, no segments/challenges import, no Strava-raw-payload processing
 * step. It shares the same import/build mutex so it never runs concurrently with a
 * Strava import/build.
 */
#[WithMonologChannel('daemon')]
#[WithMutex(lockName: LockName::IMPORT_DATA_OR_BUILD_APP)]
#[RequiresUpToDateDatabaseSchema]
#[AsCommand(name: RunEndurainImportAndBuildAppConsoleCommand::NAME, description: 'Run Endurain import')]
final class RunEndurainImportAndBuildAppConsoleCommand extends Command
{
    public const string NAME = 'app:cron:run-endurain-import';

    private const string ENDURAIN_ACTIVITY_ID_PREFIX = 'endurain-';

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly Endurain $endurain,
        private readonly DetectEndurainActivityChanges $detectEndurainActivityChanges,
        private readonly ActivityRepository $activityRepository,
        private readonly ActivityIdRepository $activityIdRepository,
        private readonly LoggerInterface $logger,
        private readonly Mutex $mutex,
        private readonly AppStatusChecker $appStatusChecker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new SymfonyStyle($input, new LoggableConsoleOutput($output, $this->logger));

        try {
            $this->mutex->acquireLock('runEndurainImportAndBuildApp');
        } catch (LockIsAlreadyAcquired) {
            // Another process is importing data, postpone import.
            $output->writeln('<comment>Postponing Endurain import, another process is importing data.</comment>');

            return Command::SUCCESS;
        }

        try {
            $this->appStatusChecker->ensureIsReadyForEndurainImport();

            $rawRemoteActivities = $this->endurain->getActivities(userId: $this->endurain->getCurrentUserId());
            $diff = $this->detectEndurainActivityChanges->diff($rawRemoteActivities);

            foreach ($diff->getNewActivityIds() as $newActivityId) {
                $this->commandBus->dispatch(new ImportEndurainActivity(
                    output: $output,
                    endurainActivityId: $this->toEndurainActivityId($newActivityId),
                ));
                $this->mutex->heartbeat();
            }

            $activityIdsToMarkForDeletion = $diff->getActivityIdsToMarkForDeletion();
            if (count($activityIdsToMarkForDeletion) > 0) {
                $this->guardAgainstDeletingEverything($activityIdsToMarkForDeletion);

                $this->activityRepository->markActivitiesForDeletion($activityIdsToMarkForDeletion);
            }

            $this->commandBus->dispatch(new CalculateActivityMetrics($output));

            $this->appStatusChecker->ensureIsReadyForBuild();
            $this->commandBus->dispatch(new RunBuild($output));
        } catch (AppIsNotReady $e) {
            $this->mutex->releaseLock();
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->mutex->releaseLock();
            throw $e;
        }

        $this->mutex->releaseLock();

        return Command::SUCCESS;
    }

    /**
     * Aborts the import if literally every locally known Endurain activity would be
     * marked for deletion. This most likely indicates a configuration or connectivity
     * issue (e.g. pointing at the wrong Endurain instance/user) rather than an actual
     * mass-deletion on the remote side, so it's safer to abort than to silently wipe
     * out all locally imported Endurain activities.
     */
    private function guardAgainstDeletingEverything(ActivityIds $activityIdsToMarkForDeletion): void
    {
        $allLocallyImportedEndurainActivityIds = $this->activityIdRepository->findAllImportedFromEndurainApi();

        if (count($activityIdsToMarkForDeletion) === count($allLocallyImportedEndurainActivityIds)
            && array_values($activityIdsToMarkForDeletion->toArray()) == $allLocallyImportedEndurainActivityIds->toArray()) {
            throw new \RuntimeException('All activities appear to be marked for deletion. This seems like a configuration issue. Aborting to prevent data loss');
        }
    }

    private function toEndurainActivityId(ActivityId $activityId): int
    {
        return (int) substr($activityId->toUnprefixedString(), strlen(self::ENDURAIN_ACTIVITY_ID_PREFIX));
    }
}
