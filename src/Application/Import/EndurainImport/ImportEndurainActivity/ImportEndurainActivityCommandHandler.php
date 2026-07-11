<?php

declare(strict_types=1);

namespace App\Application\Import\EndurainImport\ImportEndurainActivity;

use App\Application\Import\EndurainImport\ImportEndurainGear\ImportEndurainGear;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Endurain\Endurain;
use App\Domain\Endurain\Stream\EndurainParsedStreams;
use App\Domain\Endurain\Stream\EndurainStreamParser;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\Command;
use App\Infrastructure\CQRS\Command\CommandHandler;
use App\Infrastructure\Time\Clock\Clock;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

/**
 * Imports a single Endurain activity by id, including its per-point streams
 * and a re-encoded polyline (Endurain has no pre-encoded polyline of its
 * own, only raw lat/lng waypoints stored as stream data).
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
        private ActivityStreamRepository $activityStreamRepository,
        private EndurainStreamParser $endurainStreamParser,
        private CommandBus $commandBus,
        private Clock $clock,
    ) {
    }

    public function handle(Command $command): void
    {
        assert($command instanceof ImportEndurainActivity);

        $rawEndurainData = $this->endurain->getActivity($command->getEndurainActivityId());
        $activity = Activity::createFromRawEndurainData($rawEndurainData);

        // The gear referenced by this activity (if any) is imported inline,
        // on-demand, rather than as a separate batch pass: Endurain has no
        // batch daemon yet (only this single-activity tracer bullet, #12),
        // so there is no separate pipeline step to run gear import ahead of
        // activity import like Strava's ImportGear does. See
        // ImportEndurainGearCommandHandler for the full rationale.
        if (!is_null($rawEndurainData['gear_id'] ?? null)) {
            $this->commandBus->dispatch(new ImportEndurainGear(
                output: $command->getOutput(),
                endurainGearId: (int) $rawEndurainData['gear_id'],
            ));
        }

        $parsedStreams = $this->fetchParsedStreams($command->getEndurainActivityId(), $activity);
        if (null !== $parsedStreams?->getPolyline()) {
            $activity = $activity->withPolyline($parsedStreams->getPolyline());
        }

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

        if (null !== $parsedStreams) {
            foreach ($parsedStreams->getStreams() as $stream) {
                if ($this->activityStreamRepository->hasOneForActivityAndStreamType($activity->getId(), $stream->getStreamType())) {
                    continue;
                }
                $this->activityStreamRepository->add($stream);
            }
            $this->activityRepository->markActivityStreamsAsImported($activity->getId());
        }

        $command->getOutput()->writeln(sprintf(
            '  => [%s] activity: "%s - %s"',
            $isNewActivity ? 'Imported' : 'Updated',
            $activity->getName(),
            $activity->getStartDate()->format('d-m-Y'),
        ));
    }

    private function fetchParsedStreams(int $endurainActivityId, Activity $activity): ?EndurainParsedStreams
    {
        try {
            $rawStreams = $this->endurain->getAllActivityStreams($endurainActivityId);
        } catch (ClientException|RequestException $exception) {
            if (404 === $exception->getResponse()?->getStatusCode()) {
                // Streams do not exist for this activity.
                return null;
            }

            throw $exception;
        }

        return $this->endurainStreamParser->parse(
            rawStreams: $rawStreams,
            activityId: $activity->getId(),
            createdOn: $this->clock->getCurrentDateTimeImmutable(),
        );
    }
}
