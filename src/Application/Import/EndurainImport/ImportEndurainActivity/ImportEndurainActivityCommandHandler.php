<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\ImportEndurainActivity;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Endurain\Endurain;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;

/**
 * Imports a single Endurain activity by id.
 *
 * This is intentionally a single-activity operation: no mutex, no import
 * settings, no pipeline steps. It fetches, translates and persists one raw
 * Endurain activity. The full batch daemon import (mutex/settings/pipeline
 * steps/deletion handling, mirroring ImportActivitiesCommandHandler) is out
 * of scope here and will be built separately.
 */
final readonly class ImportEndurainActivityCommandHandler implements CommandHandler
{
    public function __construct(
        private Endurain $endurain,
        private ActivityRepository $activityRepository,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ImportEndurainActivity);

        $rawEndurainData = $this->endurain->getActivity($command->getEndurainActivityId());
        $activity = Activity::createFromRawEndurainData($rawEndurainData);

        $isNewActivity = !$this->activityRepository->exists($activity->getId());
        if ($isNewActivity) {
            $this->activityRepository->add(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: $rawEndurainData,
            ));
        } else {
            $this->activityRepository->update(ActivityWithRawData::fromState(
                activity: $activity,
                rawData: [
                    ...$this->activityRepository->findWithRawData($activity->getId())->getRawData(),
                    ...$rawEndurainData,
                ],
            ));
        }

        $command->getOutput()->writeln(sprintf(
            '  => [%s] activity: "%s - %s"',
            $isNewActivity ? 'Imported' : 'Updated',
            $activity->getName(),
            $activity->getStartDate()->format('d-m-Y'),
        ));
    }
}
